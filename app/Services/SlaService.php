<?php

namespace App\Services;

use App\Models\SlaPauseInterval;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($ticket, $policy): SlaTimer {
            $startedAt = now();
            $timer = SlaTimer::create([
                'ticket_id' => $ticket->id,
                'sla_policy_id' => $policy->id,
                'first_response_due_at' => $startedAt->copy()->addHours($policy->first_response_hours),
                'resolution_due_at' => $startedAt->copy()->addHours($policy->resolution_hours),
            ]);

            $ticket->loadMissing('status');
            if ($ticket->status->pauses_sla) {
                $this->pauseTimer($timer, $startedAt);
            }

            return $timer->load('pauseIntervals');
        });
    }

    public function recordFirstResponse(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $timer = SlaTimer::query()->where('ticket_id', $ticket->id)->lockForUpdate()->first();
            if (! $timer || $timer->first_responded_at) {
                return;
            }

            $completedAt = now();
            $effectiveAt = $timer->paused_at ? Carbon::parse($timer->paused_at) : $completedAt;
            $timer->update([
                'first_responded_at' => $completedAt,
                'first_response_breached' => $timer->first_response_breached
                    || ($timer->first_response_due_at && $effectiveAt->isAfter(Carbon::parse($timer->first_response_due_at))),
            ]);
        });
    }

    public function recordResolution(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $timer = SlaTimer::query()->where('ticket_id', $ticket->id)->lockForUpdate()->first();
            if (! $timer || $timer->resolved_at) {
                return;
            }

            $completedAt = now();
            $effectiveAt = $timer->paused_at ? Carbon::parse($timer->paused_at) : $completedAt;
            $timer->update([
                'resolved_at' => $completedAt,
                'resolution_breached' => $timer->resolution_breached
                    || ($timer->resolution_due_at && $effectiveAt->isAfter(Carbon::parse($timer->resolution_due_at))),
            ]);
        });
    }

    public function handleStatusChange(Ticket $ticket, TicketStatus $oldStatus, TicketStatus $newStatus): void
    {
        if ($oldStatus->pauses_sla === $newStatus->pauses_sla) {
            return;
        }

        $this->setTimerPauseState($ticket->id, $newStatus->pauses_sla, now());
    }

    public function handleStatusConfigurationChange(TicketStatus $status, bool $previouslyPaused): void
    {
        if ($previouslyPaused === $status->pauses_sla) {
            return;
        }

        $changedAt = now();
        Ticket::query()
            ->where('ticket_status_id', $status->id)
            ->whereHas('slaTimer')
            ->pluck('id')
            ->each(fn (string $ticketId) => $this->setTimerPauseState($ticketId, $status->pauses_sla, $changedAt));
    }

    private function setTimerPauseState(string $ticketId, bool $shouldPause, CarbonInterface $changedAt): void
    {
        DB::transaction(function () use ($ticketId, $shouldPause, $changedAt): void {
            $timer = SlaTimer::query()->where('ticket_id', $ticketId)->lockForUpdate()->first();
            if (! $timer) {
                return;
            }

            if ($shouldPause) {
                $this->pauseTimer($timer, $changedAt);
            } else {
                $this->resumeTimer($timer, $changedAt);
            }
        });
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

    /** @return array{first_response: array{status: string, color: string}, resolution: array{status: string, color: string}} */
    public function getSlaStatus(SlaTimer $timer): array
    {
        $firstResponse = $this->calculateSlaStatus(
            $timer->first_response_due_at,
            $timer->first_responded_at,
            $timer->first_response_breached,
            $timer->paused_at,
            $timer->created_at,
        );

        $resolution = $this->calculateSlaStatus(
            $timer->resolution_due_at,
            $timer->resolved_at,
            $timer->resolution_breached,
            $timer->paused_at,
            $timer->created_at,
        );

        return [
            'first_response' => $firstResponse,
            'resolution' => $resolution,
        ];
    }

    /** @return array{status: string, color: string} */
    private function calculateSlaStatus(
        ?string $dueAt,
        ?string $completedAt,
        bool $breached,
        ?string $pausedAt,
        string $startedAt,
    ): array {
        if (! $dueAt) {
            return ['status' => 'none', 'color' => 'gray'];
        }

        if ($completedAt) {
            return [
                'status' => $breached ? 'breached' : 'met',
                'color' => $breached ? 'red' : 'green',
            ];
        }

        if ($breached) {
            return ['status' => 'breached', 'color' => 'red'];
        }

        if ($pausedAt) {
            return ['status' => 'paused', 'color' => 'gray'];
        }

        $due = Carbon::parse($dueAt);
        $remainingSeconds = now()->diffInSeconds($due, false);

        if ($remainingSeconds <= 0) {
            return ['status' => 'breached', 'color' => 'red'];
        }

        $totalSeconds = max(1, Carbon::parse($startedAt)->diffInSeconds($due));
        $percentRemaining = ($remainingSeconds / $totalSeconds) * 100;

        if ($percentRemaining <= 25) {
            return ['status' => 'approaching', 'color' => 'yellow'];
        }

        return ['status' => 'on_track', 'color' => 'green'];
    }

    private function pauseTimer(SlaTimer $timer, CarbonInterface $pausedAt): void
    {
        if ($timer->paused_at || ($timer->first_responded_at && $timer->resolved_at)) {
            return;
        }

        $timer->update([
            'paused_at' => $pausedAt,
            'first_response_breached' => $timer->first_response_breached
                || (! $timer->first_responded_at
                    && $timer->first_response_due_at
                    && $pausedAt->isAfter(Carbon::parse($timer->first_response_due_at))),
            'resolution_breached' => $timer->resolution_breached
                || (! $timer->resolved_at
                    && $timer->resolution_due_at
                    && $pausedAt->isAfter(Carbon::parse($timer->resolution_due_at))),
        ]);

        $timer->pauseIntervals()->create([
            'started_at' => $pausedAt,
            'duration_seconds' => 0,
        ]);
    }

    private function resumeTimer(SlaTimer $timer, CarbonInterface $resumedAt): void
    {
        if (! $timer->paused_at) {
            return;
        }

        $pausedAt = Carbon::parse($timer->paused_at);
        $pausedSeconds = $resumedAt->isAfter($pausedAt)
            ? (int) $pausedAt->diffInSeconds($resumedAt)
            : 0;

        $interval = SlaPauseInterval::query()
            ->where('sla_timer_id', $timer->id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->lockForUpdate()
            ->first();

        if (! $interval) {
            $interval = $timer->pauseIntervals()->create([
                'started_at' => $pausedAt,
                'duration_seconds' => 0,
            ]);
        }

        $interval->update([
            'ended_at' => $resumedAt,
            'duration_seconds' => $pausedSeconds,
        ]);

        $updates = [
            'total_paused_seconds' => $timer->total_paused_seconds + $pausedSeconds,
            'paused_at' => null,
        ];

        if ($timer->first_response_due_at && ! $timer->first_responded_at) {
            $updates['first_response_due_at'] = Carbon::parse($timer->first_response_due_at)->addSeconds($pausedSeconds);
        }
        if ($timer->resolution_due_at && ! $timer->resolved_at) {
            $updates['resolution_due_at'] = Carbon::parse($timer->resolution_due_at)->addSeconds($pausedSeconds);
        }

        $timer->update($updates);
    }
}
