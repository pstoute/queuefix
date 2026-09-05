<?php

namespace App\Notifications;

use App\Enums\TicketUpdateEvent;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketUpdatedNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $details */
    public function __construct(
        public Ticket $ticket,
        public TicketUpdateEvent $event,
        public array $details = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'subject' => $this->ticket->subject,
            'event' => $this->event->value,
            'details' => $this->details,
        ];
    }
}
