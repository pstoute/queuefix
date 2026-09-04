<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * @phpstan-type SlaClockStatus array{
 *     state: string,
 *     color: string,
 *     due_at: string|null,
 *     started_at: string|null,
 *     remaining_seconds: int|null,
 *     original_budget_seconds: int|null,
 *     percent_remaining: float|null,
 *     warning_percent: float
 * }
 * @phpstan-type SlaStatus array{first_response: SlaClockStatus, resolution: SlaClockStatus}
 */
class SlaService
{
    public function initializeTimer(Ticket $ticket): ?SlaTimer
    {
        $policy = SlaPolicy::where('priority', $ticket->priority)
            ->where('is_active', true)
            ->first();

        if (! $policy) {
            return null;
        }

        $startedAt = now();
        $firstResponseBudgetSeconds = max(0, (int) round($policy->first_response_hours * 3600));
        $resolutionBudgetSeconds = max(0, (int) round($policy->resolution_hours * 3600));

        return SlaTimer::create([
            'ticket_id' => $ticket->id,
            'sla_policy_id' => $policy->id,
            'first_response_started_at' => $startedAt,
            'first_response_budget_seconds' => $firstResponseBudgetSeconds,
            'first_response_due_at' => $startedAt->copy()->addSeconds($firstResponseBudgetSeconds),
            'resolution_started_at' => $startedAt,
            'resolution_budget_seconds' => $resolutionBudgetSeconds,
            'resolution_due_at' => $startedAt->copy()->addSeconds($resolutionBudgetSeconds),
        ]);
    }

    public function recordFirstResponse(Ticket $ticket): void
    {
        $timer = $ticket->slaTimer;
        if (! $timer || $timer->first_responded_at) {
            return;
        }

        $timer->update([
            'first_responded_at' => now(),
            'first_response_breached' => $timer->first_response_due_at && now()->isAfter($timer->first_response_due_at),
        ]);
    }

    public function recordResolution(Ticket $ticket): void
    {
        $timer = $ticket->slaTimer;
        if (! $timer || $timer->resolved_at) {
            return;
        }

        $effectiveNow = $this->getEffectiveTime($timer);

        $timer->update([
            'resolved_at' => now(),
            'resolution_breached' => $timer->resolution_due_at && $effectiveNow->isAfter($timer->resolution_due_at),
        ]);
    }

    public function handleStatusChange(Ticket $ticket, TicketStatus $oldStatus, TicketStatus $newStatus): void
    {
        $timer = $ticket->slaTimer;
        if (! $timer) {
            return;
        }

        $wasPaused = in_array($oldStatus, TicketStatus::pausedStatuses());
        $shouldPause = in_array($newStatus, TicketStatus::pausedStatuses());

        if (! $wasPaused && $shouldPause) {
            $timer->update(['paused_at' => now()]);
        }

        if ($wasPaused && ! $shouldPause && $timer->paused_at) {
            $pausedSeconds = (int) Carbon::parse($timer->paused_at)->diffInSeconds(now());
            $timer->update([
                'total_paused_seconds' => $timer->total_paused_seconds + $pausedSeconds,
                'paused_at' => null,
            ]);

            if ($timer->first_response_due_at && ! $timer->first_responded_at) {
                $timer->update([
                    'first_response_due_at' => Carbon::parse($timer->first_response_due_at)->addSeconds($pausedSeconds),
                ]);
            }
            if ($timer->resolution_due_at && ! $timer->resolved_at) {
                $timer->update([
                    'resolution_due_at' => Carbon::parse($timer->resolution_due_at)->addSeconds($pausedSeconds),
                ]);
            }
        }
    }

    public function checkBreaches(): void
    {
        SlaTimer::whereNull('first_responded_at')
            ->where('first_response_breached', false)
            ->whereNull('paused_at')
            ->where('first_response_due_at', '<', now())
            ->update(['first_response_breached' => true]);

        SlaTimer::whereNull('resolved_at')
            ->where('resolution_breached', false)
            ->whereNull('paused_at')
            ->where('resolution_due_at', '<', now())
            ->update(['resolution_breached' => true]);
    }

    /** @return SlaStatus */
    public function getSlaStatus(?SlaTimer $timer): array
    {
        if (! $timer) {
            return [
                'first_response' => $this->emptyClockStatus(),
                'resolution' => $this->emptyClockStatus(),
            ];
        }

        $firstResponse = $this->calculateSlaStatus(
            $timer->first_response_due_at,
            $timer->first_response_started_at,
            $timer->first_response_budget_seconds,
            $timer->first_responded_at,
            $timer->first_response_breached,
            $timer->paused_at,
            $timer->total_paused_seconds,
        );

        $resolution = $this->calculateSlaStatus(
            $timer->resolution_due_at,
            $timer->resolution_started_at,
            $timer->resolution_budget_seconds,
            $timer->resolved_at,
            $timer->resolution_breached,
            $timer->paused_at,
            $timer->total_paused_seconds,
        );

        return [
            'first_response' => $firstResponse,
            'resolution' => $resolution,
        ];
    }

    private function calculateSlaStatus(
        ?CarbonInterface $dueAt,
        ?CarbonInterface $startedAt,
        ?int $budgetSeconds,
        ?CarbonInterface $completedAt,
        bool $breached,
        ?CarbonInterface $pausedAt,
        int $totalPausedSeconds,
    ): array {
        $warningPercent = max(0.0, min(100.0, (float) config('sla.warning_percent', 25)));

        if (! $dueAt) {
            return $this->emptyClockStatus($warningPercent);
        }

        $originalBudgetSeconds = $budgetSeconds;
        if ($originalBudgetSeconds === null && $startedAt) {
            $originalBudgetSeconds = max(0, $dueAt->getTimestamp() - $startedAt->getTimestamp() - $totalPausedSeconds);
        }

        if ($completedAt) {
            $effectiveCompletedAt = $pausedAt && $completedAt->isAfter($pausedAt) ? $pausedAt : $completedAt;
            $remainingSeconds = max(0, $dueAt->getTimestamp() - $effectiveCompletedAt->getTimestamp());
            $wasBreached = $breached || $effectiveCompletedAt->isAfter($dueAt);

            return $this->clockStatus(
                $wasBreached ? 'breached' : 'met',
                $dueAt,
                $startedAt,
                $remainingSeconds,
                $originalBudgetSeconds,
                $warningPercent,
            );
        }

        $effectiveNow = $pausedAt ?? now();
        $remainingSeconds = max(0, $dueAt->getTimestamp() - $effectiveNow->getTimestamp());

        if ($breached || $remainingSeconds <= 0) {
            return $this->clockStatus('breached', $dueAt, $startedAt, 0, $originalBudgetSeconds, $warningPercent);
        }

        if ($pausedAt) {
            return $this->clockStatus('paused', $dueAt, $startedAt, $remainingSeconds, $originalBudgetSeconds, $warningPercent);
        }

        $percentRemaining = $originalBudgetSeconds && $originalBudgetSeconds > 0
            ? min(100.0, max(0.0, ($remainingSeconds / $originalBudgetSeconds) * 100))
            : 0.0;

        $state = $percentRemaining <= $warningPercent ? 'approaching' : 'on_track';

        return $this->clockStatus($state, $dueAt, $startedAt, $remainingSeconds, $originalBudgetSeconds, $warningPercent);
    }

    /** @return SlaClockStatus */
    private function emptyClockStatus(?float $warningPercent = null): array
    {
        return [
            'state' => 'none',
            'color' => 'gray',
            'due_at' => null,
            'started_at' => null,
            'remaining_seconds' => null,
            'original_budget_seconds' => null,
            'percent_remaining' => null,
            'warning_percent' => $warningPercent ?? max(0.0, min(100.0, (float) config('sla.warning_percent', 25))),
        ];
    }

    /** @return SlaClockStatus */
    private function clockStatus(
        string $state,
        CarbonInterface $dueAt,
        ?CarbonInterface $startedAt,
        int $remainingSeconds,
        ?int $originalBudgetSeconds,
        float $warningPercent,
    ): array {
        $percentRemaining = $originalBudgetSeconds && $originalBudgetSeconds > 0
            ? min(100.0, max(0.0, ($remainingSeconds / $originalBudgetSeconds) * 100))
            : 0.0;

        return [
            'state' => $state,
            'color' => match ($state) {
                'breached' => 'red',
                'approaching' => 'yellow',
                'met', 'on_track' => 'green',
                default => 'gray',
            },
            'due_at' => $dueAt->toIso8601String(),
            'started_at' => $startedAt?->toIso8601String(),
            'remaining_seconds' => $remainingSeconds,
            'original_budget_seconds' => $originalBudgetSeconds,
            'percent_remaining' => round($percentRemaining, 2),
            'warning_percent' => $warningPercent,
        ];
    }

    private function getEffectiveTime(SlaTimer $timer): Carbon
    {
        if ($timer->paused_at) {
            return Carbon::parse($timer->paused_at);
        }

        return now();
    }
}
