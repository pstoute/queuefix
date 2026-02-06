<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use Carbon\Carbon;

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

        return SlaTimer::create([
            'ticket_id' => $ticket->id,
            'sla_policy_id' => $policy->id,
            'first_response_due_at' => now()->addHours($policy->first_response_hours),
            'resolution_due_at' => now()->addHours($policy->resolution_hours),
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
            $pausedSeconds = Carbon::parse($timer->paused_at)->diffInSeconds(now());
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

    public function getSlaStatus(SlaTimer $timer): array
    {
        $firstResponse = $this->calculateSlaStatus(
            $timer->first_response_due_at,
            $timer->first_responded_at,
            $timer->first_response_breached,
            $timer->paused_at,
        );

        $resolution = $this->calculateSlaStatus(
            $timer->resolution_due_at,
            $timer->resolved_at,
            $timer->resolution_breached,
            $timer->paused_at,
        );

        return [
            'first_response' => $firstResponse,
            'resolution' => $resolution,
        ];
    }

    private function calculateSlaStatus(?string $dueAt, ?string $completedAt, bool $breached, ?string $pausedAt): array
    {
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
        $remainingMinutes = now()->diffInMinutes($due, false);
        $totalMinutes = Carbon::parse($dueAt)->diffInMinutes(now());

        if ($remainingMinutes <= 0) {
            return ['status' => 'breached', 'color' => 'red'];
        }

        $percentRemaining = $totalMinutes > 0 ? ($remainingMinutes / $totalMinutes) * 100 : 100;

        if ($percentRemaining <= 25) {
            return ['status' => 'approaching', 'color' => 'yellow'];
        }

        return ['status' => 'on_track', 'color' => 'green'];
    }

    private function getEffectiveTime(SlaTimer $timer): Carbon
    {
        if ($timer->paused_at) {
            return Carbon::parse($timer->paused_at);
        }

        return now();
    }
}
