<?php

namespace App\Services;

use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TicketActivityService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        Ticket $ticket,
        TicketActivityType $eventType,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        bool $customerVisible = false,
        ?User $actor = null,
        ?string $correlationId = null,
        ?TicketActivityActorType $actorType = null,
    ): TicketActivity {
        [$resolvedActor, $resolvedActorType] = $this->resolveActor($actor, $actorType);

        return TicketActivity::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $resolvedActor?->id,
            'actor_type' => $resolvedActorType,
            'event_type' => $eventType,
            'before' => $before,
            'after' => $after,
            'summary' => $summary,
            'correlation_id' => $this->resolveCorrelationId($correlationId),
            'customer_visible' => $customerVisible,
            'created_at' => now(),
        ]);
    }

    public function recordTicketCreated(
        Ticket $ticket,
        ?string $correlationId = null,
        ?TicketActivityActorType $actorType = null,
    ): TicketActivity {
        return $this->record(
            $ticket,
            TicketActivityType::TicketCreated,
            "Ticket {$ticket->ticket_number} was created",
            after: [
                'status' => (string) $ticket->getRawOriginal('status'),
                'priority' => (string) $ticket->getRawOriginal('priority'),
                'assigned_to' => $ticket->assigned_to,
                'department_id' => $ticket->department_id,
            ],
            customerVisible: true,
            correlationId: $correlationId,
            actorType: $actorType,
        );
    }

    public function recordAssignmentChanged(Ticket $ticket, ?string $beforeId, ?User $after): TicketActivity
    {
        return $this->record(
            $ticket,
            TicketActivityType::AssignmentChanged,
            $after ? "Assigned ticket to {$after->name}" : 'Ticket was unassigned',
            before: ['assigned_to' => $beforeId],
            after: ['assigned_to' => $after?->id, 'assignee_name' => $after?->name],
        );
    }

    public function recordStatusChanged(
        Ticket $ticket,
        string $before,
        string $after,
        string $label,
        ?string $correlationId = null,
        ?TicketActivityActorType $actorType = null,
    ): TicketActivity {
        return $this->record(
            $ticket,
            TicketActivityType::StatusChanged,
            "Ticket status changed to {$label}",
            before: ['status' => $before],
            after: ['status' => $after],
            customerVisible: true,
            correlationId: $correlationId,
            actorType: $actorType,
        );
    }

    public function recordPriorityChanged(Ticket $ticket, string $before, string $after, string $label): TicketActivity
    {
        return $this->record(
            $ticket,
            TicketActivityType::PriorityChanged,
            "Ticket priority changed to {$label}",
            before: ['priority' => $before],
            after: ['priority' => $after],
        );
    }

    public function recordDepartmentChanged(
        Ticket $ticket,
        ?Department $before,
        ?Department $after,
    ): TicketActivity {
        return $this->record(
            $ticket,
            TicketActivityType::DepartmentChanged,
            $after ? "Moved ticket to {$after->name}" : 'Removed ticket from its department',
            before: ['department_id' => $before?->id, 'department_name' => $before?->name],
            after: ['department_id' => $after?->id, 'department_name' => $after?->name],
        );
    }

    /**
     * @param  array<int, array{id: string, name: string}>  $before
     * @param  array<int, array{id: string, name: string}>  $after
     */
    public function recordTagsChanged(
        Ticket $ticket,
        Tag $tag,
        string $action,
        array $before,
        array $after,
    ): TicketActivity {
        return $this->record(
            $ticket,
            TicketActivityType::TagsChanged,
            ($action === 'added' ? 'Added' : 'Removed')." tag {$tag->name}",
            before: ['tags' => $before],
            after: ['tags' => $after],
        );
    }

    public function recordWatcherChanged(Ticket $ticket, User $watcher, bool $added): TicketActivity
    {
        return $this->record(
            $ticket,
            $added ? TicketActivityType::WatcherAdded : TicketActivityType::WatcherRemoved,
            ($added ? 'Added' : 'Removed')." watcher {$watcher->name}",
            before: $added ? null : ['watcher_id' => $watcher->id, 'watcher_name' => $watcher->name],
            after: $added ? ['watcher_id' => $watcher->id, 'watcher_name' => $watcher->name] : null,
        );
    }

    public function recordEscalation(Ticket $ticket, bool $triggered, string $reason): TicketActivity
    {
        return $this->record(
            $ticket,
            $triggered ? TicketActivityType::EscalationTriggered : TicketActivityType::EscalationCleared,
            $triggered ? 'Ticket escalation triggered' : 'Ticket escalation cleared',
            before: $triggered ? null : ['escalated' => true],
            after: ['escalated' => $triggered, 'reason' => $reason],
        );
    }

    public function recordTicketMerged(Ticket $primary, Ticket $secondary, string $beforeStatus): TicketActivity
    {
        return $this->record(
            $primary,
            TicketActivityType::TicketMerged,
            "Merged ticket {$secondary->ticket_number} into this ticket",
            before: ['merged_ticket_id' => $secondary->id, 'merged_ticket_status' => $beforeStatus],
            after: [
                'merged_ticket_id' => $secondary->id,
                'merged_ticket_status' => (string) $secondary->getRawOriginal('status'),
            ],
        );
    }

    public function recordTicketSplit(Ticket $original, Ticket $created): TicketActivity
    {
        return $this->record(
            $original,
            TicketActivityType::TicketSplit,
            "Split messages into ticket {$created->ticket_number}",
            after: ['split_ticket_id' => $created->id, 'split_ticket_number' => $created->ticket_number],
        );
    }

    public function recordAttachmentAdded(
        Ticket $ticket,
        Attachment $attachment,
        ?string $correlationId = null,
        ?TicketActivityActorType $actorType = null,
    ): TicketActivity {
        return $this->record(
            $ticket,
            TicketActivityType::AttachmentAdded,
            "Added attachment {$attachment->filename}",
            after: [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ],
            correlationId: $correlationId,
            actorType: $actorType,
        );
    }

    public function recordAttachmentRemoved(Ticket $ticket, Attachment $attachment): TicketActivity
    {
        return $this->record(
            $ticket,
            TicketActivityType::AttachmentRemoved,
            "Removed attachment {$attachment->filename}",
            before: [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ],
        );
    }

    public function recordOutboundDelivery(
        Ticket $ticket,
        string $messageId,
        bool $delivered,
        string $correlationId,
        ?string $reason = null,
    ): TicketActivity {
        return $this->record(
            $ticket,
            $delivered ? TicketActivityType::OutboundDelivered : TicketActivityType::OutboundFailed,
            $delivered ? 'Outbound reply delivered' : 'Outbound reply delivery failed',
            after: array_filter([
                'message_id' => $messageId,
                'status' => $delivered ? 'delivered' : 'failed',
                'reason' => $reason,
            ], fn (mixed $value): bool => $value !== null),
            actorType: TicketActivityActorType::System,
            correlationId: $correlationId,
        );
    }

    /** @return array{User|null, TicketActivityActorType} */
    private function resolveActor(
        ?User $actor,
        ?TicketActivityActorType $actorType,
    ): array {
        if ($actorType === TicketActivityActorType::System) {
            return [null, TicketActivityActorType::System];
        }

        $authenticatedUser = $actor ?? Auth::guard('web')->user();
        if ($authenticatedUser instanceof User) {
            return [$authenticatedUser, TicketActivityActorType::User];
        }

        if ($actorType === TicketActivityActorType::Customer || Auth::guard('customer')->check()) {
            return [null, TicketActivityActorType::Customer];
        }

        return [null, TicketActivityActorType::System];
    }

    private function resolveCorrelationId(?string $correlationId): string
    {
        if ($correlationId !== null && $correlationId !== '') {
            return Str::limit($correlationId, 255, '');
        }

        $request = request();
        $existing = $request->attributes->get('ticket_activity_correlation_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $resolved = $request->header('X-Request-ID')
            ?? $request->header('X-Correlation-ID')
            ?? (string) Str::uuid();
        $resolved = Str::limit($resolved, 255, '');
        $request->attributes->set('ticket_activity_correlation_id', $resolved);

        return $resolved;
    }
}
