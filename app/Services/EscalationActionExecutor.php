<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Models\EscalationRule;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;

class EscalationActionExecutor
{
    public function __construct(private TicketService $ticketService) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function execute(EscalationRule $rule, Ticket $ticket, array $action): array
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            EscalationRule::ACTION_ASSIGN => $this->assign($ticket, $action),
            EscalationRule::ACTION_PRIORITY => $this->changePriority($ticket, $action),
            EscalationRule::ACTION_STATUS => $this->changeStatus($ticket, $action),
            EscalationRule::ACTION_INTERNAL_NOTE => $this->addInternalNote($rule, $ticket, $action),
            EscalationRule::ACTION_ADD_TAG => $this->changeTag($ticket, $action, true),
            EscalationRule::ACTION_REMOVE_TAG => $this->changeTag($ticket, $action, false),
            EscalationRule::ACTION_NOTIFY => $this->notify($rule, $ticket, $action),
            default => throw new InvalidArgumentException("Unsupported escalation action: {$type}"),
        };
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function assign(Ticket $ticket, array $action): array
    {
        $userId = $this->requiredString($action, 'user_id');
        $user = User::query()->where('is_active', true)->findOrFail($userId);
        $before = ['assigned_to' => $ticket->assigned_to];
        $ticket->update(['assigned_to' => $user->id]);

        if (config('tickets.auto_watch.assignee')) {
            $ticket->watchers()->syncWithoutDetaching([$user->id]);
        }

        return ['before' => $before, 'after' => ['assigned_to' => $user->id]];
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function changePriority(Ticket $ticket, array $action): array
    {
        $priority = TicketPriority::tryFrom($this->requiredString($action, 'priority'))
            ?? throw new InvalidArgumentException('The escalation priority is invalid.');
        $currentPriority = (string) $ticket->getRawOriginal('priority');
        $before = ['priority' => $currentPriority];

        if ($currentPriority !== $priority->value) {
            $ticket->update([
                'priority' => $priority,
                'priority_changed_at' => now(),
            ]);
        }

        return ['before' => $before, 'after' => ['priority' => $priority->value]];
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function changeStatus(Ticket $ticket, array $action): array
    {
        $status = TicketStatus::query()->findOrFail($this->requiredString($action, 'status_id'));
        $before = ['status_id' => $ticket->ticket_status_id];
        $updated = $this->ticketService->updateStatus($ticket, $status);

        return ['before' => $before, 'after' => ['status_id' => $updated->ticket_status_id]];
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function addInternalNote(EscalationRule $rule, Ticket $ticket, array $action): array
    {
        $body = $this->requiredString($action, 'body');
        $message = $this->ticketService->addMessage($ticket, [
            'sender_type' => EscalationRule::class,
            'sender_id' => $rule->id,
            'type' => MessageType::InternalNote,
            'body_text' => $body,
        ], notifyWatchers: false);

        return [
            'before' => ['message_id' => null],
            'after' => ['message_id' => $message->id, 'body' => $body],
        ];
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function changeTag(Ticket $ticket, array $action, bool $add): array
    {
        $tag = Tag::query()->findOrFail($this->requiredString($action, 'tag_id'));
        $wasAttached = $ticket->tags()->whereKey($tag->id)->exists();

        if ($add) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        } else {
            $ticket->tags()->detach($tag->id);
        }

        return [
            'before' => ['tag_id' => $tag->id, 'attached' => $wasAttached],
            'after' => ['tag_id' => $tag->id, 'attached' => $add],
        ];
    }

    /** @param array<string, mixed> $action
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function notify(EscalationRule $rule, Ticket $ticket, array $action): array
    {
        $channel = (string) ($action['channel'] ?? 'database');
        if ($channel !== 'database') {
            throw new InvalidArgumentException('Only the database notification channel is supported.');
        }

        $requestedIds = $this->stringList($action['user_ids'] ?? []);
        $recipientIds = $requestedIds !== []
            ? $requestedIds
            : $ticket->watchers()->pluck('users.id')
                ->when($ticket->assigned_to !== null, fn ($ids) => $ids->push($ticket->assigned_to))
                ->unique()
                ->values()
                ->all();
        $recipients = User::query()
            ->where('is_active', true)
            ->whereIn('id', $recipientIds)
            ->orderBy('id')
            ->get();

        if ($recipients->isEmpty()) {
            throw new RuntimeException('The notification action has no active recipients.');
        }
        if ($requestedIds !== [] && $recipients->count() !== count(array_unique($requestedIds))) {
            throw new RuntimeException('One or more notification recipients are unavailable.');
        }

        Notification::send($recipients, new TicketEscalatedNotification($ticket, $rule));
        $deliveredIds = $recipients->modelKeys();

        return [
            'before' => ['channel' => $channel, 'recipient_ids' => $deliveredIds, 'dispatched' => false],
            'after' => ['channel' => $channel, 'recipient_ids' => $deliveredIds, 'dispatched' => true],
        ];
    }

    /** @param array<string, mixed> $action */
    private function requiredString(array $action, string $key): string
    {
        $value = $action[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Escalation action requires {$key}.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
