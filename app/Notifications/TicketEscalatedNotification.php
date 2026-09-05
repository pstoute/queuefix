<?php

namespace App\Notifications;

use App\Models\EscalationRule;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketEscalatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public EscalationRule $rule,
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
            'event' => 'escalated',
            'details' => [
                'rule_id' => $this->rule->id,
                'rule_name' => $this->rule->name,
            ],
        ];
    }
}
