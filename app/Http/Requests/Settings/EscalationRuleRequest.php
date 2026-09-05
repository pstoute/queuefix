<?php

namespace App\Http\Requests\Settings;

use App\Enums\TicketPriority;
use App\Models\EscalationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EscalationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger' => ['required', 'string', Rule::in(EscalationRule::triggers())],
            'trigger_config' => ['required', 'array'],
            'trigger_config.minutes' => ['sometimes', 'integer', 'min:1', 'max:525600'],
            'trigger_config.clock' => ['sometimes', 'string', Rule::in(['any', 'first_response', 'resolution'])],
            'trigger_config.status_id' => ['sometimes', 'uuid', 'exists:ticket_statuses,id'],
            'trigger_config.priority' => ['sometimes', 'string', Rule::enum(TicketPriority::class)],
            'filters' => ['required', 'array'],
            'filters.status_ids' => ['sometimes', 'array'],
            'filters.status_ids.*' => ['uuid', 'distinct', 'exists:ticket_statuses,id'],
            'filters.priorities' => ['sometimes', 'array'],
            'filters.priorities.*' => ['string', 'distinct', Rule::enum(TicketPriority::class)],
            'filters.department_ids' => ['sometimes', 'array'],
            'filters.department_ids.*' => ['uuid', 'distinct', 'exists:departments,id'],
            'filters.assignee_state' => ['sometimes', 'string', Rule::in(['any', 'assigned', 'unassigned'])],
            'filters.mailbox_ids' => ['sometimes', 'array'],
            'filters.mailbox_ids.*' => ['uuid', 'distinct', 'exists:mailboxes,id'],
            'filters.tag_ids' => ['sometimes', 'array'],
            'filters.tag_ids.*' => ['uuid', 'distinct', 'exists:tags,id'],
            'actions' => ['required', 'array', 'min:1', 'max:20'],
            'actions.*' => ['required', 'array'],
            'actions.*.type' => ['required', 'string', Rule::in(EscalationRule::actionTypes())],
            'actions.*.user_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'actions.*.priority' => ['sometimes', 'string', Rule::enum(TicketPriority::class)],
            'actions.*.status_id' => ['sometimes', 'uuid', 'exists:ticket_statuses,id'],
            'actions.*.body' => ['sometimes', 'string', 'max:10000'],
            'actions.*.tag_id' => ['sometimes', 'uuid', 'exists:tags,id'],
            'actions.*.channel' => ['sometimes', 'string', Rule::in(['database'])],
            'actions.*.user_ids' => ['sometimes', 'array'],
            'actions.*.user_ids.*' => ['uuid', 'distinct', 'exists:users,id'],
            'include_closed' => ['required', 'boolean'],
            'include_archived' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $trigger = $this->input('trigger');
            $config = $this->input('trigger_config', []);
            $requiredTriggerKey = match ($trigger) {
                EscalationRule::TRIGGER_NO_FIRST_RESPONSE, EscalationRule::TRIGGER_NO_ACTIVITY => 'minutes',
                EscalationRule::TRIGGER_SLA_APPROACHING, EscalationRule::TRIGGER_SLA_BREACHED => 'clock',
                EscalationRule::TRIGGER_STATUS_ENTERED => 'status_id',
                EscalationRule::TRIGGER_PRIORITY_CHANGED => 'priority',
                default => null,
            };

            if ($requiredTriggerKey !== null && ! isset($config[$requiredTriggerKey])) {
                $validator->errors()->add("trigger_config.{$requiredTriggerKey}", "The {$requiredTriggerKey} value is required for this trigger.");
            }

            $allowedTriggerKeys = $requiredTriggerKey === null ? [] : [$requiredTriggerKey];
            if (array_diff(array_keys($config), $allowedTriggerKeys) !== []) {
                $validator->errors()->add('trigger_config', 'The trigger configuration contains unsupported fields.');
            }

            $allowedFilterKeys = ['status_ids', 'priorities', 'department_ids', 'assignee_state', 'mailbox_ids', 'tag_ids'];
            if (array_diff(array_keys($this->input('filters', [])), $allowedFilterKeys) !== []) {
                $validator->errors()->add('filters', 'The filter configuration contains unsupported fields.');
            }

            foreach ($this->input('actions', []) as $index => $action) {
                $type = $action['type'] ?? null;
                $requiredKey = match ($type) {
                    EscalationRule::ACTION_ASSIGN => 'user_id',
                    EscalationRule::ACTION_PRIORITY => 'priority',
                    EscalationRule::ACTION_STATUS => 'status_id',
                    EscalationRule::ACTION_INTERNAL_NOTE => 'body',
                    EscalationRule::ACTION_ADD_TAG, EscalationRule::ACTION_REMOVE_TAG => 'tag_id',
                    EscalationRule::ACTION_NOTIFY => 'channel',
                    default => null,
                };
                $allowedKeys = match ($type) {
                    EscalationRule::ACTION_NOTIFY => ['type', 'channel', 'user_ids'],
                    default => $requiredKey === null ? ['type'] : ['type', $requiredKey],
                };

                if ($requiredKey !== null && ! isset($action[$requiredKey])) {
                    $validator->errors()->add("actions.{$index}.{$requiredKey}", "The {$requiredKey} value is required for this action.");
                }
                if (array_diff(array_keys($action), $allowedKeys) !== []) {
                    $validator->errors()->add("actions.{$index}", 'The action contains unsupported fields.');
                }
            }

            if ($this->boolean('include_archived')) {
                $hasMutatingAction = false;
                $actions = $this->input('actions', []);
                if (is_array($actions)) {
                    foreach ($actions as $action) {
                        if (! is_array($action) || ($action['type'] ?? null) !== EscalationRule::ACTION_NOTIFY) {
                            $hasMutatingAction = true;
                            break;
                        }
                    }
                }

                if ($hasMutatingAction) {
                    $validator->errors()->add('include_archived', 'Archived merged tickets only support notification actions.');
                }
            }
        }];
    }
}
