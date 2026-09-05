<?php

namespace App\Notifications;

use App\Models\TicketMention;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketMentionNotification extends Notification
{
    use Queueable;

    public function __construct(public TicketMention $mention) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'staff_mention',
            'ticket_id' => $this->mention->ticket_id,
            'message_id' => $this->mention->message_id,
            'actor_id' => $this->mention->actor_id,
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actorName = $this->mention->actor()->value('name');
        if (! is_string($actorName)) {
            $actorName = 'A teammate';
        }

        return (new MailMessage)
            ->subject("You were mentioned on ticket #{$this->mention->ticket->ticket_number}")
            ->line("{$actorName} mentioned you in an internal note.")
            ->action('View internal note', $this->url());
    }

    public function url(): string
    {
        return route('agent.tickets.messages.show', [
            'ticket' => $this->mention->ticket_id,
            'message' => $this->mention->message_id,
        ]);
    }
}
