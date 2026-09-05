<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketReadState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TicketReadStateService
{
    public function markRead(Ticket $ticket, User $user, ?Message $cursor = null): TicketReadState
    {
        if ($cursor !== null && $cursor->ticket_id !== $ticket->id) {
            throw new InvalidArgumentException('The read cursor must belong to the ticket.');
        }

        return DB::transaction(function () use ($ticket, $user, $cursor): TicketReadState {
            Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            $state = TicketReadState::query()
                ->where('ticket_id', $ticket->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                return TicketReadState::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'last_read_at' => now(),
                    'last_read_message_id' => $cursor?->id,
                ]);
            }

            $currentCursor = $state->lastReadMessage;

            if ($cursor === null || ($currentCursor !== null && $this->compareMessages($cursor, $currentCursor) < 0)) {
                return $state;
            }

            $readAt = now();
            if ($state->last_read_at->greaterThan($readAt)) {
                $readAt = $state->last_read_at;
            }

            $state->update([
                'last_read_at' => $readAt,
                'last_read_message_id' => $cursor->id,
            ]);

            return $state->refresh();
        }, 3);
    }

    public function latestVisibleMessage(Ticket $ticket, User $user): ?Message
    {
        /** @var Message|null $message */
        $message = $ticket->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $message;
    }

    /** @param Builder<Ticket> $tickets */
    public function addUnreadCount(Builder $tickets, User $user): void
    {
        $tickets->withCount([
            'messages as unread_count' => function (Builder $messages) use ($user): void {
                $this->applyRelevantMessageConstraint($messages, $user);
                $this->applyUnreadMessageConstraint($messages, $user);
            },
        ]);
    }

    /** @param Builder<Ticket> $tickets */
    public function applyUnreadTicketConstraint(Builder $tickets, User $user): void
    {
        $tickets->whereHas('messages', function (Builder $messages) use ($user): void {
            $this->applyRelevantMessageConstraint($messages, $user);
            $this->applyUnreadMessageConstraint($messages, $user);
        });
    }

    public function unreadTicketCount(User $user): int
    {
        $tickets = Ticket::query();
        $this->applyUnreadTicketConstraint($tickets, $user);

        return $tickets->count();
    }

    /** @param Builder<Model> $messages */
    private function applyRelevantMessageConstraint(Builder $messages, User $user): void
    {
        $messages->where(function (Builder $relevant) use ($user): void {
            $relevant
                ->whereExists(function (QueryBuilder $tickets) use ($user): void {
                    $tickets->selectRaw('1')
                        ->from('tickets as relevant_tickets')
                        ->whereColumn('relevant_tickets.id', 'messages.ticket_id')
                        ->where('relevant_tickets.assigned_to', $user->id);
                })
                ->orWhereExists(function (QueryBuilder $watchers) use ($user): void {
                    $watchers->selectRaw('1')
                        ->from('ticket_watchers')
                        ->whereColumn('ticket_watchers.ticket_id', 'messages.ticket_id')
                        ->where('ticket_watchers.user_id', $user->id);
                });
        });
    }

    /** @param Builder<Model> $messages */
    private function applyUnreadMessageConstraint(Builder $messages, User $user): void
    {
        $messages
            ->where(function (Builder $notOwnMessage) use ($user): void {
                $notOwnMessage
                    ->where('messages.sender_type', '!=', User::class)
                    ->orWhere('messages.sender_id', '!=', $user->id);
            })
            ->where(function (Builder $unread) use ($user): void {
                $unread
                    ->whereNotExists(fn (QueryBuilder $state) => $this->readStateQuery($state, $user))
                    ->orWhereExists(function (QueryBuilder $state) use ($user): void {
                        $this->readStateQuery($state, $user)
                            ->where(function (QueryBuilder $afterCursor): void {
                                $afterCursor
                                    ->where(function (QueryBuilder $withoutMessageCursor): void {
                                        $withoutMessageCursor
                                            ->whereNull('ticket_read_states.last_read_message_id')
                                            ->whereColumn('messages.created_at', '>=', 'ticket_read_states.last_read_at');
                                    })
                                    ->orWhere(function (QueryBuilder $withMessageCursor): void {
                                        $withMessageCursor
                                            ->whereNotNull('ticket_read_states.last_read_message_id')
                                            ->where(function (QueryBuilder $laterMessage): void {
                                                $cursorTimestamp = '(select read_cursor.created_at from messages as read_cursor where read_cursor.id = ticket_read_states.last_read_message_id)';

                                                $laterMessage
                                                    ->whereRaw("messages.created_at > {$cursorTimestamp}")
                                                    ->orWhere(function (QueryBuilder $sameTimestamp) use ($cursorTimestamp): void {
                                                        $sameTimestamp
                                                            ->whereRaw("messages.created_at = {$cursorTimestamp}")
                                                            ->whereColumn('messages.id', '>', 'ticket_read_states.last_read_message_id');
                                                    });
                                            });
                                    });
                            });
                    });
            });
    }

    private function readStateQuery(QueryBuilder $state, User $user): QueryBuilder
    {
        return $state->selectRaw('1')
            ->from('ticket_read_states')
            ->whereColumn('ticket_read_states.ticket_id', 'messages.ticket_id')
            ->where('ticket_read_states.user_id', $user->id);
    }

    private function compareMessages(Message $left, Message $right): int
    {
        if ($left->created_at->lessThan($right->created_at)) {
            return -1;
        }

        if ($left->created_at->greaterThan($right->created_at)) {
            return 1;
        }

        return strcmp($left->id, $right->id);
    }
}
