<?php

namespace App\Notifications;

use App\Models\TicketRating;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketLowRatingNotification extends Notification
{
    use Queueable;

    public function __construct(public TicketRating $ticketRating) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $ticket = $this->ticketRating->ticket;

        return [
            'event' => 'low_ticket_rating',
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'rating' => $this->ticketRating->rating,
            'url' => $this->url(),
        ];
    }

    public function url(): string
    {
        return route('agent.tickets.show', $this->ticketRating->ticket_id);
    }
}
