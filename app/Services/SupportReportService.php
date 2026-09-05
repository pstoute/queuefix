<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Models\SlaPauseInterval;
use App\Models\SlaTimer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupportReportService
{
    /**
     * @return array{
     *   summary: array<string, int|float|null>,
     *   breakdowns: array<string, list<array<string, int|float|string|null>>>
     * }
     */
    public function generate(
        CarbonImmutable $fromUtc,
        CarbonImmutable $toUtcExclusive,
        ?string $departmentId = null,
        ?string $agentId = null,
    ): array {
        $from = $fromUtc->format('Y-m-d H:i:s');
        $to = $toUtcExclusive->format('Y-m-d H:i:s');

        $ticketTotals = $this->ticketTotals($from, $to, $departmentId, $agentId);
        $responseTimers = $this->completedTimers('first_responded_at', $from, $to, $departmentId, $agentId);
        $resolutionTimers = $this->completedTimers('resolved_at', $from, $to, $departmentId, $agentId);
        $ratings = DB::table('ticket_ratings')
            ->join('tickets', 'tickets.id', '=', 'ticket_ratings.ticket_id')
            ->where('ticket_ratings.submitted_at', '>=', $from)
            ->where('ticket_ratings.submitted_at', '<', $to)
            ->when($departmentId !== null, fn (Builder $query) => $query->where('tickets.department_id', $departmentId))
            ->when($agentId !== null, fn (Builder $query) => $query->where('tickets.assigned_to', $agentId))
            ->pluck('ticket_ratings.rating')
            ->map(fn (mixed $rating): int => (int) $rating);

        $responseDurations = $responseTimers
            ->map(fn (SlaTimer $timer): int => $this->activeDurationSeconds($timer, $timer->first_responded_at))
            ->all();
        $resolutionDurations = $resolutionTimers
            ->map(fn (SlaTimer $timer): int => $this->activeDurationSeconds($timer, $timer->resolved_at))
            ->all();

        return [
            'summary' => [
                'created_count' => (int) $ticketTotals->created_count,
                'resolved_count' => (int) $ticketTotals->resolved_count,
                'currently_open_count' => (int) $ticketTotals->currently_open_count,
                'first_response_sla_percent' => $this->compliancePercent($responseTimers, 'first_response_breached'),
                'resolution_sla_percent' => $this->compliancePercent($resolutionTimers, 'resolution_breached'),
                'first_response_median_seconds' => $this->median($responseDurations),
                'first_response_average_seconds' => $this->average($responseDurations),
                'resolution_median_seconds' => $this->median($resolutionDurations),
                'resolution_average_seconds' => $this->average($resolutionDurations),
                'rating_count' => $ratings->count(),
                'average_csat' => $ratings->isEmpty() ? null : round((float) $ratings->avg(), 2),
                'low_rating_percent' => $ratings->isEmpty()
                    ? null
                    : round($ratings->filter(fn (int $rating): bool => $rating <= 2)->count() / $ratings->count() * 100, 1),
            ],
            'breakdowns' => [
                'department' => $this->breakdown('department', $from, $to, $departmentId, $agentId),
                'priority' => $this->breakdown('priority', $from, $to, $departmentId, $agentId),
                'status' => $this->breakdown('status', $from, $to, $departmentId, $agentId),
                'assignee' => $this->breakdown('assignee', $from, $to, $departmentId, $agentId),
            ],
        ];
    }

    private function ticketTotals(string $from, string $to, ?string $departmentId, ?string $agentId): object
    {
        return DB::table('tickets')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.ticket_status_id')
            ->when($departmentId !== null, fn (Builder $query) => $query->where('tickets.department_id', $departmentId))
            ->when($agentId !== null, fn (Builder $query) => $query->where('tickets.assigned_to', $agentId))
            ->selectRaw('SUM(CASE WHEN tickets.created_at >= ? AND tickets.created_at < ? THEN 1 ELSE 0 END) AS created_count', [$from, $to])
            ->selectRaw('SUM(CASE WHEN tickets.resolved_at >= ? AND tickets.resolved_at < ? THEN 1 ELSE 0 END) AS resolved_count', [$from, $to])
            ->selectRaw('SUM(CASE WHEN ticket_statuses.is_closed = false AND tickets.created_at < ? THEN 1 ELSE 0 END) AS currently_open_count', [$to])
            ->where(function (Builder $builder) use ($from, $to): void {
                $builder->where(function (Builder $event) use ($from, $to): void {
                    $event->where('tickets.created_at', '>=', $from)->where('tickets.created_at', '<', $to);
                })->orWhere(function (Builder $event) use ($from, $to): void {
                    $event->where('tickets.resolved_at', '>=', $from)->where('tickets.resolved_at', '<', $to);
                })->orWhere(function (Builder $event) use ($to): void {
                    $event->where('ticket_statuses.is_closed', false)->where('tickets.created_at', '<', $to);
                });
            })
            ->first() ?? (object) [
                'created_count' => 0,
                'resolved_count' => 0,
                'currently_open_count' => 0,
            ];
    }

    /** @return Collection<int, SlaTimer> */
    private function completedTimers(
        string $column,
        string $from,
        string $to,
        ?string $departmentId,
        ?string $agentId,
    ): Collection {
        return SlaTimer::query()
            ->with('pauseIntervals')
            ->where($column, '>=', $from)
            ->where($column, '<', $to)
            ->whereHas('ticket', function (EloquentBuilder $query) use ($departmentId, $agentId): void {
                if ($departmentId !== null) {
                    $query->where('department_id', $departmentId);
                }

                if ($agentId !== null) {
                    $query->where('assigned_to', $agentId);
                }
            })
            ->get();
    }

    private function activeDurationSeconds(SlaTimer $timer, CarbonInterface|string|null $completedAt): int
    {
        if ($completedAt === null) {
            return 0;
        }

        $startedAt = CarbonImmutable::parse($timer->created_at);
        $endedAt = CarbonImmutable::parse($completedAt);
        $grossSeconds = max(0, (int) $startedAt->diffInSeconds($endedAt));

        $pausedSeconds = $timer->pauseIntervals->sum(
            function (SlaPauseInterval $interval) use ($startedAt, $endedAt): int {
                $pauseStart = CarbonImmutable::parse($interval->started_at);
                $pauseEnd = $interval->ended_at !== null
                    ? CarbonImmutable::parse($interval->ended_at)
                    : $endedAt;

                $overlapStart = $pauseStart->isAfter($startedAt) ? $pauseStart : $startedAt;
                $overlapEnd = $pauseEnd->isBefore($endedAt) ? $pauseEnd : $endedAt;

                return $overlapEnd->isAfter($overlapStart)
                    ? (int) $overlapStart->diffInSeconds($overlapEnd)
                    : 0;
            },
        );

        return max(0, $grossSeconds - (int) $pausedSeconds);
    }

    /** @param Collection<int, SlaTimer> $timers */
    private function compliancePercent(Collection $timers, string $breachColumn): ?float
    {
        if ($timers->isEmpty()) {
            return null;
        }

        $met = $timers->filter(fn (SlaTimer $timer): bool => ! (bool) $timer->getAttribute($breachColumn))->count();

        return round($met / $timers->count() * 100, 1);
    }

    /** @param list<int> $values */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /** @param list<int> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : round(($values[$middle - 1] + $values[$middle]) / 2, 1);
    }

    /** @return list<array<string, int|float|string|null>> */
    private function breakdown(
        string $dimension,
        string $from,
        string $to,
        ?string $departmentId,
        ?string $agentId,
    ): array {
        $query = DB::table('tickets')
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.ticket_status_id')
            ->leftJoin('sla_timers', 'sla_timers.ticket_id', '=', 'tickets.id')
            ->leftJoin('ticket_ratings', function (JoinClause $join) use ($from, $to): void {
                $join->on('ticket_ratings.ticket_id', '=', 'tickets.id')
                    ->where('ticket_ratings.submitted_at', '>=', $from)
                    ->where('ticket_ratings.submitted_at', '<', $to);
            })
            ->when($departmentId !== null, fn (Builder $builder) => $builder->where('tickets.department_id', $departmentId))
            ->when($agentId !== null, fn (Builder $builder) => $builder->where('tickets.assigned_to', $agentId));

        [$idColumn, $labelColumn] = match ($dimension) {
            'department' => $this->joinDimension($query, 'departments', 'department_id'),
            'assignee' => $this->joinDimension($query, 'users', 'assigned_to'),
            'priority' => ['tickets.priority', 'tickets.priority'],
            'status' => ['ticket_statuses.id', 'ticket_statuses.name'],
            default => throw new \InvalidArgumentException("Unsupported report dimension: {$dimension}"),
        };

        $rows = $query
            ->selectRaw("{$idColumn} AS group_id, {$labelColumn} AS label")
            ->selectRaw('SUM(CASE WHEN tickets.created_at >= ? AND tickets.created_at < ? THEN 1 ELSE 0 END) AS created_count', [$from, $to])
            ->selectRaw('SUM(CASE WHEN tickets.resolved_at >= ? AND tickets.resolved_at < ? THEN 1 ELSE 0 END) AS resolved_count', [$from, $to])
            ->selectRaw('SUM(CASE WHEN ticket_statuses.is_closed = false AND tickets.created_at < ? THEN 1 ELSE 0 END) AS currently_open_count', [$to])
            ->selectRaw('SUM(CASE WHEN sla_timers.first_responded_at >= ? AND sla_timers.first_responded_at < ? THEN 1 ELSE 0 END) AS first_response_total', [$from, $to])
            ->selectRaw('SUM(CASE WHEN sla_timers.first_responded_at >= ? AND sla_timers.first_responded_at < ? AND sla_timers.first_response_breached = false THEN 1 ELSE 0 END) AS first_response_met', [$from, $to])
            ->selectRaw('SUM(CASE WHEN sla_timers.resolved_at >= ? AND sla_timers.resolved_at < ? THEN 1 ELSE 0 END) AS resolution_total', [$from, $to])
            ->selectRaw('SUM(CASE WHEN sla_timers.resolved_at >= ? AND sla_timers.resolved_at < ? AND sla_timers.resolution_breached = false THEN 1 ELSE 0 END) AS resolution_met', [$from, $to])
            ->selectRaw('COUNT(ticket_ratings.id) AS rating_count')
            ->selectRaw('AVG(ticket_ratings.rating) AS average_csat')
            ->selectRaw('SUM(CASE WHEN ticket_ratings.rating <= 2 THEN 1 ELSE 0 END) AS low_rating_count')
            ->where(function (Builder $builder) use ($from, $to): void {
                $builder->where(function (Builder $event) use ($from, $to): void {
                    $event->where('tickets.created_at', '>=', $from)->where('tickets.created_at', '<', $to);
                })->orWhere(function (Builder $event) use ($from, $to): void {
                    $event->where('tickets.resolved_at', '>=', $from)->where('tickets.resolved_at', '<', $to);
                })->orWhere(function (Builder $event) use ($to): void {
                    $event->where('ticket_statuses.is_closed', false)->where('tickets.created_at', '<', $to);
                })->orWhere(function (Builder $event) use ($from, $to): void {
                    $event->where('sla_timers.first_responded_at', '>=', $from)->where('sla_timers.first_responded_at', '<', $to);
                })->orWhere(function (Builder $event) use ($from, $to): void {
                    $event->where('sla_timers.resolved_at', '>=', $from)->where('sla_timers.resolved_at', '<', $to);
                })->orWhereNotNull('ticket_ratings.id');
            })
            ->groupBy($idColumn, $labelColumn)
            ->get();

        return $rows
            ->map(function (object $row) use ($dimension): array {
                $ratingCount = (int) $row->rating_count;
                $firstResponseTotal = (int) $row->first_response_total;
                $resolutionTotal = (int) $row->resolution_total;
                $label = $row->label;

                if ($dimension === 'priority' && is_string($label)) {
                    $label = TicketPriority::from($label)->label();
                }

                return [
                    'key' => $row->group_id === null ? 'unassigned' : (string) $row->group_id,
                    'label' => is_string($label) ? $label : ($dimension === 'department' ? 'No department' : 'Unassigned'),
                    'created_count' => (int) $row->created_count,
                    'resolved_count' => (int) $row->resolved_count,
                    'currently_open_count' => (int) $row->currently_open_count,
                    'first_response_sla_percent' => $firstResponseTotal === 0
                        ? null
                        : round((int) $row->first_response_met / $firstResponseTotal * 100, 1),
                    'resolution_sla_percent' => $resolutionTotal === 0
                        ? null
                        : round((int) $row->resolution_met / $resolutionTotal * 100, 1),
                    'rating_count' => $ratingCount,
                    'average_csat' => $ratingCount === 0 ? null : round((float) $row->average_csat, 2),
                    'low_rating_percent' => $ratingCount === 0
                        ? null
                        : round((int) $row->low_rating_count / $ratingCount * 100, 1),
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return array{string, string} */
    private function joinDimension(Builder $query, string $table, string $foreignKey): array
    {
        $query->leftJoin($table, "{$table}.id", '=', "tickets.{$foreignKey}");

        return ["{$table}.id", "{$table}.name"];
    }
}
