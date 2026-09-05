<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\EscalationRule;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class EscalationRuleMatcher
{
    public function __construct(private SlaService $slaService) {}

    /**
     * @return array{
     *   matched: bool,
     *   eligible: bool,
     *   trigger_matched: bool,
     *   filters_matched: bool,
     *   trigger_window: string|null,
     *   trigger_context: array<string, mixed>,
     *   reasons: list<string>,
     *   actions: list<array<string, mixed>>
     * }
     */
    public function preview(EscalationRule $rule, Ticket $ticket, ?CarbonInterface $evaluatedAt = null): array
    {
        $evaluatedAt ??= now();
        $ticket->loadMissing(['status', 'tags', 'slaTimer']);
        $reasons = [];
        $eligible = true;

        if ($ticket->isMerged()) {
            if (! $rule->include_archived) {
                $eligible = false;
                $reasons[] = 'Archived merged tickets are excluded by this rule.';
            } elseif ($this->hasMutatingAction($rule)) {
                $eligible = false;
                $reasons[] = 'Archived merged tickets are immutable; only notification actions are permitted.';
            }
        }
        if ($ticket->status->is_closed && ! $rule->include_closed) {
            $eligible = false;
            $reasons[] = 'Closed tickets are excluded by this rule.';
        }

        $trigger = $this->matchTrigger($rule, $ticket, $evaluatedAt);
        if (! $trigger['matched']) {
            $reasons[] = $trigger['reason'];
        }

        $filtersMatched = $this->matchFilters($rule, $ticket, $reasons);

        $actions = $rule->getAttribute('actions');
        $plannedActions = [];
        if (is_array($actions)) {
            foreach (array_values($actions) as $index => $action) {
                if (is_array($action)) {
                    $plannedActions[] = $action + ['order' => $index + 1];
                }
            }
        }

        return [
            'matched' => $eligible && $trigger['matched'] && $filtersMatched,
            'eligible' => $eligible,
            'trigger_matched' => $trigger['matched'],
            'filters_matched' => $filtersMatched,
            'trigger_window' => $trigger['window'],
            'trigger_context' => $trigger['context'] + ['evaluated_at' => $evaluatedAt->toIso8601String()],
            'reasons' => $reasons,
            'actions' => $plannedActions,
        ];
    }

    /** @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string} */
    private function matchTrigger(EscalationRule $rule, Ticket $ticket, CarbonInterface $evaluatedAt): array
    {
        /** @var array<string, mixed> $config */
        $config = $rule->trigger_config;

        return match ($rule->trigger) {
            EscalationRule::TRIGGER_NO_FIRST_RESPONSE => $this->matchNoFirstResponse($ticket, $config, $evaluatedAt),
            EscalationRule::TRIGGER_NO_ACTIVITY => $this->matchNoActivity($ticket, $config, $evaluatedAt),
            EscalationRule::TRIGGER_SLA_APPROACHING => $this->matchSla($ticket, $config, 'approaching'),
            EscalationRule::TRIGGER_SLA_BREACHED => $this->matchSla($ticket, $config, 'breached'),
            EscalationRule::TRIGGER_STATUS_ENTERED => $this->matchStatusEntered($ticket, $config),
            EscalationRule::TRIGGER_PRIORITY_CHANGED => $this->matchPriorityChanged($ticket, $config),
            default => $this->noMatch('Unsupported trigger.'),
        };
    }

    /** @param array<string, mixed> $config
     * @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string}
     */
    private function matchNoFirstResponse(Ticket $ticket, array $config, CarbonInterface $evaluatedAt): array
    {
        $minutes = (int) ($config['minutes'] ?? 0);
        $createdAt = Carbon::parse($ticket->created_at);
        $thresholdAt = $createdAt->copy()->addMinutes($minutes);
        $hasFirstResponse = $ticket->messages()
            ->where('type', MessageType::Reply->value)
            ->where('sender_type', User::class)
            ->exists();
        $matched = $minutes > 0 && ! $hasFirstResponse && $evaluatedAt->greaterThanOrEqualTo($thresholdAt);

        return [
            'matched' => $matched,
            'window' => $createdAt->toIso8601String(),
            'context' => [
                'minutes' => $minutes,
                'threshold_at' => $thresholdAt->toIso8601String(),
                'has_first_response' => $hasFirstResponse,
            ],
            'reason' => 'The first-response threshold has not been reached or a staff response exists.',
        ];
    }

    /** @param array<string, mixed> $config
     * @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string}
     */
    private function matchNoActivity(Ticket $ticket, array $config, CarbonInterface $evaluatedAt): array
    {
        $minutes = (int) ($config['minutes'] ?? 0);
        $lastActivityAt = Carbon::parse($ticket->last_activity_at);
        $thresholdAt = $lastActivityAt->copy()->addMinutes($minutes);
        $matched = $minutes > 0 && $evaluatedAt->greaterThanOrEqualTo($thresholdAt);

        return [
            'matched' => $matched,
            'window' => $lastActivityAt->toIso8601String(),
            'context' => [
                'minutes' => $minutes,
                'last_activity_at' => $lastActivityAt->toIso8601String(),
                'threshold_at' => $thresholdAt->toIso8601String(),
            ],
            'reason' => 'The inactivity threshold has not been reached.',
        ];
    }

    /** @param array<string, mixed> $config
     * @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string}
     */
    private function matchSla(Ticket $ticket, array $config, string $expectedStatus): array
    {
        if (! $ticket->slaTimer) {
            return $this->noMatch('The ticket has no SLA timer.');
        }

        $clock = (string) ($config['clock'] ?? 'any');
        $summary = $this->slaService->getSlaStatus($ticket->slaTimer);
        $clockNames = $clock === 'any' ? ['first_response', 'resolution'] : [$clock];
        $matchedClocks = collect($clockNames)
            ->filter(fn (string $name): bool => isset($summary[$name]) && $summary[$name]['status'] === $expectedStatus)
            ->values();
        $dueAt = [
            'first_response' => $ticket->slaTimer->first_response_due_at,
            'resolution' => $ticket->slaTimer->resolution_due_at,
        ];
        $window = $matchedClocks
            ->map(fn (string $name): string => $name.':'.($dueAt[$name] ?? 'none'))
            ->implode('|');

        return [
            'matched' => $matchedClocks->isNotEmpty(),
            'window' => $window !== '' ? $window : null,
            'context' => [
                'clock' => $clock,
                'expected_status' => $expectedStatus,
                'matched_clocks' => $matchedClocks->all(),
                'summary' => $summary,
            ],
            'reason' => "No selected SLA clock is {$expectedStatus}.",
        ];
    }

    /** @param array<string, mixed> $config
     * @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string}
     */
    private function matchStatusEntered(Ticket $ticket, array $config): array
    {
        $statusId = (string) ($config['status_id'] ?? '');
        $changedAt = $ticket->status_changed_at !== null ? Carbon::parse($ticket->status_changed_at) : null;
        $matched = $changedAt !== null && $ticket->ticket_status_id === $statusId;

        return [
            'matched' => $matched,
            'window' => $changedAt?->toIso8601String(),
            'context' => [
                'status_id' => $ticket->ticket_status_id,
                'status_changed_at' => $changedAt?->toIso8601String(),
            ],
            'reason' => 'The ticket did not enter the configured status.',
        ];
    }

    /** @param array<string, mixed> $config
     * @return array{matched: bool, window: string|null, context: array<string, mixed>, reason: string}
     */
    private function matchPriorityChanged(Ticket $ticket, array $config): array
    {
        $priority = (string) ($config['priority'] ?? '');
        $changedAt = $ticket->priority_changed_at !== null ? Carbon::parse($ticket->priority_changed_at) : null;
        $currentPriority = (string) $ticket->getRawOriginal('priority');
        $matched = $changedAt !== null && $currentPriority === $priority;

        return [
            'matched' => $matched,
            'window' => $changedAt?->toIso8601String(),
            'context' => [
                'priority' => $currentPriority,
                'priority_changed_at' => $changedAt?->toIso8601String(),
            ],
            'reason' => 'The ticket did not change to the configured priority.',
        ];
    }

    /** @param list<string> $reasons */
    private function matchFilters(EscalationRule $rule, Ticket $ticket, array &$reasons): bool
    {
        /** @var array<string, mixed> $filters */
        $filters = $rule->filters;
        $matched = true;

        $checks = [
            'status_ids' => $ticket->ticket_status_id,
            'priorities' => (string) $ticket->getRawOriginal('priority'),
            'department_ids' => $ticket->department_id,
            'mailbox_ids' => $ticket->mailbox_id,
        ];

        foreach ($checks as $key => $actual) {
            $allowed = $this->stringList($filters[$key] ?? []);
            if ($allowed !== [] && ($actual === null || ! in_array($actual, $allowed, true))) {
                $matched = false;
                $reasons[] = "Filter {$key} did not match.";
            }
        }

        $assigneeState = (string) ($filters['assignee_state'] ?? 'any');
        if (($assigneeState === 'assigned' && $ticket->assigned_to === null)
            || ($assigneeState === 'unassigned' && $ticket->assigned_to !== null)) {
            $matched = false;
            $reasons[] = 'The assignee-state filter did not match.';
        }

        $requiredTags = $this->stringList($filters['tag_ids'] ?? []);
        if ($requiredTags !== []) {
            $ticketTags = $ticket->tags->modelKeys();
            if (array_diff($requiredTags, $ticketTags) !== []) {
                $matched = false;
                $reasons[] = 'The ticket does not have every required tag.';
            }
        }

        return $matched;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function hasMutatingAction(EscalationRule $rule): bool
    {
        $actions = $rule->getAttribute('actions');

        return is_array($actions) && collect($actions)->contains(
            fn (mixed $action): bool => ! is_array($action)
                || ($action['type'] ?? null) !== EscalationRule::ACTION_NOTIFY,
        );
    }

    /** @return array{matched: false, window: null, context: array<string, mixed>, reason: string} */
    private function noMatch(string $reason): array
    {
        return [
            'matched' => false,
            'window' => null,
            'context' => [],
            'reason' => $reason,
        ];
    }
}
