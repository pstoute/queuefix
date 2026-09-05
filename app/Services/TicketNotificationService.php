<?php

namespace App\Services;

use App\Enums\TicketUpdateEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketUpdatedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

class TicketNotificationService
{
    /** @param array<string, mixed> $details */
    public function notify(
        Ticket $ticket,
        TicketUpdateEvent $event,
        ?User $actor = null,
        array $details = [],
    ): void {
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($ticket): void {
                $query->whereHas('watchedTickets', fn (Builder $ticketQuery) => $ticketQuery->whereKey($ticket->id));

                if ($ticket->assigned_to !== null) {
                    $query->orWhere('users.id', $ticket->assigned_to);
                }
            })
            ->when($actor !== null, fn (Builder $query) => $query->whereKeyNot($actor->id))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new TicketUpdatedNotification($ticket, $event, $details));
    }

    /** @param array<string, mixed> $details */
    public function notifyEscalated(Ticket $ticket, ?User $actor = null, array $details = []): void
    {
        $this->notify($ticket, TicketUpdateEvent::Escalated, $actor, $details);
    }
}
