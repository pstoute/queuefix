<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\Ticket;
use App\Models\TicketMention;
use App\Models\TicketReadState;
use App\Models\TicketSplitEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketSplitService
{
    public function __construct(
        private TicketService $ticketService,
        private SlaService $slaService,
    ) {}

    /** @param list<string> $messageIds */
    public function split(Ticket $source, array $messageIds, string $subject, User $actor): Ticket
    {
        return DB::transaction(function () use ($source, $messageIds, $subject, $actor): Ticket {
            $source = Ticket::query()->lockForUpdate()->findOrFail($source->id);

            if ($source->isMerged()) {
                $this->fail('Messages cannot be split from a ticket that has already been merged.');
            }

            $selected = Message::query()
                ->where('ticket_id', $source->id)
                ->whereIn('id', $messageIds)
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($messageIds === [] || $selected->count() !== count($messageIds)) {
                $this->fail('Every selected message must belong to this ticket.');
            }

            $tagIds = $source->tags()->lockForUpdate()->pluck('tags.id')->all();
            $customer = $source->customer()->firstOrFail();
            $splitAt = now();

            $newTicket = $this->ticketService->createTicket([
                'subject' => $subject,
                'priority' => $source->priority,
            ], $customer, $source->mailbox_id, $source->department_id, $actor);

            if ($tagIds !== []) {
                $newTicket->tags()->sync($tagIds);
            }

            Message::query()
                ->whereIn('id', $messageIds)
                ->whereNull('original_ticket_id')
                ->update(['original_ticket_id' => $source->id]);
            Message::query()
                ->whereIn('id', $messageIds)
                ->update(['ticket_id' => $newTicket->id]);
            TicketMention::query()
                ->whereIn('message_id', $messageIds)
                ->update(['ticket_id' => $newTicket->id]);
            MessageCcRecipient::query()
                ->whereIn('message_id', $messageIds)
                ->update([
                    'ticket_id' => $newTicket->id,
                    'ticket_cc_recipient_id' => null,
                ]);

            TicketReadState::query()->where('ticket_id', $source->id)->delete();
            $source->update(['last_activity_at' => $splitAt]);
            $newTicket->update(['last_activity_at' => $splitAt]);

            if ($selected->contains(fn (Message $message): bool => $message->type === MessageType::Reply->value
                && $message->sender_type === User::class
            )) {
                $this->slaService->recordFirstResponse($newTicket);
            }

            $event = [
                'actor_id' => $actor->id,
                'message_count' => $selected->count(),
                'occurred_at' => $splitAt,
            ];
            TicketSplitEvent::create($event + [
                'ticket_id' => $source->id,
                'counterpart_ticket_id' => $newTicket->id,
                'event_type' => TicketSplitEvent::SOURCE_SPLIT,
            ]);
            TicketSplitEvent::create($event + [
                'ticket_id' => $newTicket->id,
                'counterpart_ticket_id' => $source->id,
                'event_type' => TicketSplitEvent::NEW_TICKET_CREATED,
            ]);

            return $newTicket->fresh();
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['message_ids' => $message]);
    }
}
