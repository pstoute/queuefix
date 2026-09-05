<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\Ticket;
use App\Models\TicketCcAudit;
use App\Models\TicketCcRecipient;
use App\Models\TicketMention;
use App\Models\TicketMergeEvent;
use App\Models\TicketReadState;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketMergeService
{
    public function __construct(private SlaService $slaService) {}

    public function merge(Ticket $source, Ticket $target, User $actor): Ticket
    {
        return DB::transaction(function () use ($source, $target, $actor): Ticket {
            if ($source->is($target)) {
                $this->fail('Choose a different ticket to merge.');
            }

            /** @var Collection<int, Ticket> $tickets */
            $tickets = Ticket::query()
                ->whereKey([$source->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $tickets->get($source->id) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Ticket::class, [$source->id]);
            $target = $tickets->get($target->id) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Ticket::class, [$target->id]);

            $this->assertMergeable($source, $target);

            $mergedAt = now();
            $sourceStatus = $source->status()->lockForUpdate()->firstOrFail();
            $closedStatus = TicketStatus::systemClosedStatus();

            $sourceTagIds = $source->tags()->lockForUpdate()->pluck('tags.id')->all();
            $sourceWatcherIds = $source->watchers()->lockForUpdate()->pluck('users.id')->all();

            $this->mergeCcRecipients($source, $target, $actor);

            Message::query()
                ->where('ticket_id', $source->id)
                ->whereNull('original_ticket_id')
                ->update(['original_ticket_id' => $source->id]);
            Message::query()
                ->where('ticket_id', $source->id)
                ->update(['ticket_id' => $target->id]);
            TicketMention::query()
                ->where('ticket_id', $source->id)
                ->update(['ticket_id' => $target->id]);
            MessageCcRecipient::query()
                ->where('ticket_id', $source->id)
                ->update(['ticket_id' => $target->id]);

            if ($sourceTagIds !== []) {
                $target->tags()->syncWithoutDetaching($sourceTagIds);
            }
            if ($sourceWatcherIds !== []) {
                $target->watchers()->syncWithoutDetaching($sourceWatcherIds);
            }

            $source->tags()->detach();
            $source->watchers()->detach();
            TicketReadState::query()->where('ticket_id', $source->id)->delete();

            $source->update([
                'ticket_status_id' => $closedStatus->id,
                'status_changed_at' => $mergedAt,
                'resolved_at' => $source->resolved_at ?? $mergedAt,
                'closed_at' => $source->closed_at ?? $mergedAt,
                'last_activity_at' => $mergedAt,
                'merged_into_ticket_id' => $target->id,
                'merged_at' => $mergedAt,
                'merged_by' => $actor->id,
            ]);

            if (! $sourceStatus->is($closedStatus)) {
                $this->slaService->handleStatusChange($source, $sourceStatus, $closedStatus);
            }
            $this->slaService->recordResolution($source);

            $target->update(['last_activity_at' => $mergedAt]);

            TicketMergeEvent::create([
                'ticket_id' => $source->id,
                'counterpart_ticket_id' => $target->id,
                'actor_id' => $actor->id,
                'event_type' => TicketMergeEvent::SOURCE_MERGED,
                'occurred_at' => $mergedAt,
            ]);
            TicketMergeEvent::create([
                'ticket_id' => $target->id,
                'counterpart_ticket_id' => $source->id,
                'actor_id' => $actor->id,
                'event_type' => TicketMergeEvent::TARGET_RECEIVED,
                'occurred_at' => $mergedAt,
            ]);

            return $target->fresh();
        }, 3);
    }

    private function assertMergeable(Ticket $source, Ticket $target): void
    {
        if ($source->merged_into_ticket_id !== null) {
            $this->fail('The source ticket has already been merged.');
        }

        if ($target->merged_into_ticket_id !== null) {
            $this->fail('The target ticket has already been merged into another ticket.');
        }

        if ($source->customer_id !== $target->customer_id) {
            $this->fail('Tickets must belong to the same customer before their histories can be merged.');
        }
    }

    private function mergeCcRecipients(Ticket $source, Ticket $target, User $actor): void
    {
        $excludedEmails = collect([
            $target->customer()->value('email'),
            $target->mailbox()->value('email'),
        ])->filter()->map(fn (string $email): string => strtolower($email))->flip();

        $sourceRecipients = TicketCcRecipient::query()
            ->where('ticket_id', $source->id)
            ->where('validation_state', TicketCcRecipient::VALIDATION_APPROVED)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->get();

        foreach ($sourceRecipients as $sourceRecipient) {
            if ($excludedEmails->has(strtolower($sourceRecipient->email))) {
                continue;
            }

            $recipient = TicketCcRecipient::query()
                ->where('ticket_id', $target->id)
                ->where('email', $sourceRecipient->email)
                ->lockForUpdate()
                ->first();
            $changed = false;

            if ($recipient === null) {
                $recipient = TicketCcRecipient::create([
                    'ticket_id' => $target->id,
                    'email' => $sourceRecipient->email,
                    'display_name' => $sourceRecipient->display_name,
                    'source' => 'ticket_merge',
                    'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
                    'added_by_type' => $actor->getMorphClass(),
                    'added_by_id' => $actor->id,
                    'approved_at' => now(),
                    'metadata' => ['merged_from_ticket_id' => $source->id],
                ]);
                $changed = true;
            } elseif ($recipient->removed_at !== null) {
                $recipient->update([
                    'display_name' => $sourceRecipient->display_name ?? $recipient->display_name,
                    'source' => 'ticket_merge',
                    'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
                    'added_by_type' => $actor->getMorphClass(),
                    'added_by_id' => $actor->id,
                    'approved_at' => now(),
                    'removed_at' => null,
                    'metadata' => ['merged_from_ticket_id' => $source->id],
                ]);
                $changed = true;
            }

            if ($changed) {
                TicketCcAudit::create([
                    'ticket_id' => $target->id,
                    'ticket_cc_recipient_id' => $recipient->id,
                    'actor_type' => $actor->getMorphClass(),
                    'actor_id' => $actor->id,
                    'event' => 'recipient_added',
                    'email' => $recipient->email,
                    'metadata' => [
                        'source' => 'ticket_merge',
                        'merged_from_ticket_id' => $source->id,
                    ],
                ]);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['merge_ticket_id' => $message]);
    }
}
