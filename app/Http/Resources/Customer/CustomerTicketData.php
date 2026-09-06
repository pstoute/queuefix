<?php

namespace App\Http\Resources\Customer;

use App\Enums\MessageType;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;

final class CustomerTicketData
{
    /** @return array<string, mixed> */
    public function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'last_activity_at' => $ticket->last_activity_at,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'messages' => $ticket->messages
                ->map(function ($message): array {
                    assert($message instanceof Message);

                    return $this->message($message);
                })
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'type' => MessageType::Reply->value,
            'sender_kind' => $message->sender_type === Customer::class ? 'customer' : 'support',
            'sender' => $this->participant($message->sender),
            'body_text' => $message->body_text,
            'body_html' => $message->body_html,
            'created_at' => $message->created_at,
            'attachments' => $message->attachments
                ->map(function ($attachment): array {
                    assert($attachment instanceof Attachment);

                    return $this->attachment($attachment);
                })
                ->values()
                ->all(),
        ];
    }

    /** @return array{name: string, avatar: string|null}|null */
    private function participant(mixed $participant): ?array
    {
        if (! $participant instanceof User && ! $participant instanceof Customer) {
            return null;
        }

        return [
            'name' => $participant->name,
            'avatar' => $participant->avatar,
        ];
    }

    /** @return array<string, mixed> */
    private function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'size' => $attachment->size,
            'scan_status' => $attachment->scan_status->value,
            'url' => $attachment->getAttribute('url'),
        ];
    }
}
