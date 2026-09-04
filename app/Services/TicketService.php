<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\TicketActivityActorType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private SlaService $slaService,
        private TicketActivityService $activityService,
    ) {}

    public function createTicket(
        array $data,
        Customer $customer,
        ?string $mailboxId = null,
        ?string $departmentId = null,
        ?string $activityCorrelationId = null,
        ?TicketActivityActorType $activityActorType = null,
    ): Ticket {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $data,
                    $customer,
                    $mailboxId,
                    $departmentId,
                    $activityCorrelationId,
                    $activityActorType,
                ) {
                    $ticket = Ticket::create([
                        'subject' => $data['subject'],
                        'status' => TicketStatus::Open,
                        'priority' => $data['priority'] ?? TicketPriority::Normal,
                        'customer_id' => $customer->id,
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'mailbox_id' => $mailboxId,
                        'department_id' => $departmentId,
                        'last_activity_at' => now(),
                    ]);

                    if (! empty($data['body'])) {
                        $this->addMessage($ticket, [
                            'type' => MessageType::Reply,
                            'body_text' => strip_tags($data['body']),
                            'body_html' => $data['body'],
                            'sender_type' => Customer::class,
                            'sender_id' => $customer->id,
                        ]);
                    }

                    $this->slaService->initializeTimer($ticket);
                    $this->activityService->recordTicketCreated(
                        $ticket,
                        $activityCorrelationId,
                        $activityActorType,
                    );

                    return $ticket;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to create ticket after retrying ticket-number allocation.');
    }

    public function addMessage(Ticket $ticket, array $data): Message
    {
        $message = $ticket->messages()->create([
            'sender_type' => $data['sender_type'],
            'sender_id' => $data['sender_id'],
            'type' => $data['type'],
            'body_text' => $data['body_text'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references' => $data['references'] ?? null,
        ]);

        $ticket->update(['last_activity_at' => now()]);

        if ($data['type'] === MessageType::Reply || $data['type'] === MessageType::Reply->value) {
            $senderIsAgent = $data['sender_type'] === User::class;

            if ($senderIsAgent && $ticket->slaTimer && ! $ticket->slaTimer->first_responded_at) {
                $this->slaService->recordFirstResponse($ticket);
            }
        }

        return $message;
    }

    public function updateStatus(
        Ticket $ticket,
        TicketStatus $newStatus,
        ?string $activityCorrelationId = null,
        ?TicketActivityActorType $activityActorType = null,
    ): Ticket {
        return DB::transaction(function () use (
            $ticket,
            $newStatus,
            $activityCorrelationId,
            $activityActorType,
        ) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $oldStatus = TicketStatus::from((string) $ticket->getRawOriginal('status'));

            if ($oldStatus === $newStatus) {
                return $ticket;
            }

            $ticket->update([
                'status' => $newStatus,
                'last_activity_at' => now(),
            ]);

            $this->slaService->handleStatusChange($ticket, $oldStatus, $newStatus);

            if ($newStatus === TicketStatus::Resolved || $newStatus === TicketStatus::Closed) {
                $this->slaService->recordResolution($ticket);
            }

            $this->activityService->recordStatusChanged(
                $ticket,
                $oldStatus->value,
                $newStatus->value,
                $newStatus->label(),
                $activityCorrelationId,
                $activityActorType,
            );

            return $ticket->fresh();
        });
    }

    public function updatePriority(Ticket $ticket, TicketPriority $newPriority): Ticket
    {
        return DB::transaction(function () use ($ticket, $newPriority) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $oldPriority = TicketPriority::from((string) $ticket->getRawOriginal('priority'));

            if ($oldPriority === $newPriority) {
                return $ticket;
            }

            $ticket->update([
                'priority' => $newPriority,
                'last_activity_at' => now(),
            ]);
            $this->activityService->recordPriorityChanged(
                $ticket,
                $oldPriority->value,
                $newPriority->value,
                $newPriority->label(),
            );

            return $ticket->fresh();
        });
    }

    public function assignTicket(Ticket $ticket, ?User $agent): Ticket
    {
        return DB::transaction(function () use ($ticket, $agent) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $oldAssigneeId = $ticket->assigned_to;

            if ($oldAssigneeId === $agent?->id) {
                return $ticket;
            }

            $ticket->update([
                'assigned_to' => $agent?->id,
                'last_activity_at' => now(),
            ]);
            $this->activityService->recordAssignmentChanged($ticket, $oldAssigneeId, $agent);

            return $ticket->fresh();
        });
    }

    public function updateDepartment(Ticket $ticket, ?Department $department): Ticket
    {
        return DB::transaction(function () use ($ticket, $department) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $relatedDepartment = $ticket->department;
            $oldDepartment = $relatedDepartment instanceof Department ? $relatedDepartment : null;

            if ($ticket->department_id === $department?->id) {
                return $ticket;
            }

            $ticket->update([
                'department_id' => $department?->id,
                'last_activity_at' => now(),
            ]);
            $this->activityService->recordDepartmentChanged($ticket, $oldDepartment, $department);

            return $ticket->fresh();
        });
    }

    public function attachTag(Ticket $ticket, Tag $tag): Ticket
    {
        return DB::transaction(function () use ($ticket, $tag) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = $this->tagSnapshot($ticket);
            $changes = $ticket->tags()->syncWithoutDetaching([$tag->id]);

            if ($changes['attached'] !== []) {
                $this->activityService->recordTagsChanged(
                    $ticket,
                    $tag,
                    'added',
                    $before,
                    $this->tagSnapshot($ticket),
                );
            }

            return $ticket->fresh();
        });
    }

    public function detachTag(Ticket $ticket, Tag $tag): Ticket
    {
        return DB::transaction(function () use ($ticket, $tag) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = $this->tagSnapshot($ticket);

            if ($ticket->tags()->detach($tag->id) > 0) {
                $this->activityService->recordTagsChanged(
                    $ticket,
                    $tag,
                    'removed',
                    $before,
                    $this->tagSnapshot($ticket),
                );
            }

            return $ticket->fresh();
        });
    }

    public function mergeTickets(Ticket $primary, Ticket $secondary): Ticket
    {
        return DB::transaction(function () use ($primary, $secondary) {
            $tickets = Ticket::query()
                ->whereIn('id', [$primary->id, $secondary->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $primary = $tickets->get($primary->id);
            $secondary = $tickets->get($secondary->id);

            if (! $primary instanceof Ticket || ! $secondary instanceof Ticket) {
                throw new \RuntimeException('Unable to lock both tickets for merge.');
            }

            $secondary->messages()->update(['ticket_id' => $primary->id]);

            $secondaryTags = $secondary->tags()->pluck('tags.id')->toArray();
            $primary->tags()->syncWithoutDetaching($secondaryTags);

            $secondaryStatus = TicketStatus::from((string) $secondary->getRawOriginal('status'));
            $secondary->update([
                'status' => TicketStatus::Closed,
                'last_activity_at' => now(),
            ]);
            $primary->update(['last_activity_at' => now()]);
            $this->activityService->recordTicketMerged($primary, $secondary, $secondaryStatus->value);

            return $primary->fresh();
        });
    }

    /** @return array<int, array{id: string, name: string}> */
    private function tagSnapshot(Ticket $ticket): array
    {
        return $ticket->tags()
            ->orderBy('name')
            ->get(['tags.id', 'tags.name'])
            ->map(fn ($tag): array => [
                'id' => (string) $tag->getKey(),
                'name' => (string) $tag->getAttribute('name'),
            ])
            ->all();
    }

    public function getNextTicketNumber(): string
    {
        $prefix = Setting::get('ticket_prefix', 'QF');
        $currentCounter = (int) Setting::get('ticket_counter', '0');

        return $prefix.'-'.($currentCounter + 1);
    }
}
