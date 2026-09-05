<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketMention;
use App\Models\User;
use App\Notifications\TicketMentionNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class TicketMentionService
{
    public function __construct(private MentionParser $parser) {}

    public function syncMentions(Ticket $ticket, Message $message, User $actor): void
    {
        if ($message->ticket_id !== $ticket->id || ! $this->isInternalNote($message)) {
            return;
        }

        $mentionsToNotify = $this->reserveMentionNotifications($ticket, $message, $actor);
        $this->sendReservedNotifications($mentionsToNotify);
    }

    public function updateInternalNote(
        Ticket $ticket,
        Message $message,
        User $actor,
        string $body,
    ): Message {
        if (
            $message->ticket_id !== $ticket->id
            || ! $this->isInternalNote($message)
            || $message->sender_type !== User::class
            || $message->sender_id !== $actor->id
        ) {
            throw new RuntimeException('Only the author can edit this internal note.');
        }

        $message->update([
            'body_text' => $body,
            'body_html' => null,
        ]);

        $this->syncMentions($ticket, $message->fresh(), $actor);

        return $message->fresh('mentions.mentionedUser');
    }

    /** @return Collection<int, TicketMention> */
    private function reserveMentionNotifications(
        Ticket $ticket,
        Message $message,
        User $actor,
    ): Collection {
        $handles = $this->parser->handles($message->body_text ?? '');
        $mentionedUsers = User::query()
            ->whereIn('handle', $handles)
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $ticket))
            ->keyBy('id');

        return DB::transaction(function () use ($ticket, $message, $actor, $mentionedUsers): Collection {
            Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            $existingMentions = TicketMention::query()
                ->where('message_id', $message->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('mentioned_user_id');
            $desiredUserIds = $mentionedUsers->keys();

            TicketMention::query()
                ->where('message_id', $message->id)
                ->whereNotIn('mentioned_user_id', $desiredUserIds)
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            $reserved = collect();

            foreach ($mentionedUsers as $mentionedUser) {
                $mention = $existingMentions->get($mentionedUser->id);

                if ($mention === null) {
                    $mention = TicketMention::create([
                        'ticket_id' => $ticket->id,
                        'message_id' => $message->id,
                        'mentioned_user_id' => $mentionedUser->id,
                        'actor_id' => $actor->id,
                    ]);
                } elseif ($mention->removed_at !== null) {
                    $mention->update(['removed_at' => null]);
                }

                if ($mention->notified_at === null) {
                    $mention->update(['notified_at' => now()]);
                    $reserved->push($mention->load(['ticket', 'actor', 'mentionedUser']));
                }
            }

            return $reserved;
        }, 3);
    }

    /** @param Collection<int, TicketMention> $mentions */
    private function sendReservedNotifications(Collection $mentions): void
    {
        foreach ($mentions as $mention) {
            $mention->refresh();
            $recipient = $mention->mentionedUser;

            if (
                $mention->removed_at !== null
                || $recipient === null
                || ! $recipient->is_active
                || ! Gate::forUser($recipient)->allows('view', $mention->ticket)
            ) {
                $mention->update(['notified_at' => null]);

                continue;
            }

            try {
                Notification::send($recipient, new TicketMentionNotification($mention));
            } catch (Throwable $exception) {
                $mention->update(['notified_at' => null]);

                throw $exception;
            }
        }
    }

    private function isInternalNote(Message $message): bool
    {
        $type = $message->getRawOriginal('type');

        return ($type instanceof MessageType ? $type->value : $type) === MessageType::InternalNote->value;
    }
}
