<?php

namespace App\Services;

use App\Exceptions\EscalationActionException;
use App\Models\EscalationActionLog;
use App\Models\EscalationLog;
use App\Models\EscalationRule;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EscalationRuleEvaluator
{
    public function __construct(
        private EscalationRuleMatcher $matcher,
        private EscalationActionExecutor $actionExecutor,
    ) {}

    /** @return array{evaluated: int, applied: int, failed: int, skipped: int} */
    public function evaluate(): array
    {
        $stats = ['evaluated' => 0, 'applied' => 0, 'failed' => 0, 'skipped' => 0];

        EscalationRule::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (EscalationRule $rule) use (&$stats): void {
                Ticket::query()
                    ->with(['status', 'tags', 'slaTimer'])
                    ->orderBy('id')
                    ->each(function (Ticket $ticket) use ($rule, &$stats): void {
                        $preview = $this->matcher->preview($rule, $ticket);
                        $stats['evaluated']++;

                        if (! $preview['matched'] || $preview['trigger_window'] === null) {
                            return;
                        }

                        $result = $this->apply($rule, $ticket, $preview['trigger_window'], $preview['trigger_context']);
                        if (isset($stats[$result])) {
                            $stats[$result]++;
                        } else {
                            $stats['skipped']++;
                        }
                    });
            });

        return $stats;
    }

    /** @param array<string, mixed> $triggerContext */
    public function apply(EscalationRule $rule, Ticket $ticket, string $triggerWindow, array $triggerContext): string
    {
        $idempotencyKey = hash('sha256', implode('|', [
            $rule->id,
            $ticket->id,
            $rule->trigger,
            $triggerWindow,
        ]));
        $log = EscalationLog::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'escalation_rule_id' => $rule->id,
                'ticket_id' => $ticket->id,
                'trigger_window' => $triggerWindow,
                'trigger_context' => $triggerContext,
                'status' => EscalationLog::STATUS_PENDING,
                'attempts' => 0,
                'actor' => 'system',
            ],
        );

        $attempt = $this->claim($log);
        if ($attempt === null) {
            return 'skipped';
        }

        try {
            return DB::transaction(function () use ($log, $rule, $ticket, $attempt, $triggerWindow): string {
                $lockedLog = EscalationLog::query()->lockForUpdate()->findOrFail($log->id);
                $lockedRule = EscalationRule::query()->lockForUpdate()->findOrFail($rule->id);
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                $preview = $this->matcher->preview($lockedRule, $lockedTicket);

                if (! $lockedRule->is_active
                    || ! $preview['matched']
                    || $preview['trigger_window'] !== $triggerWindow) {
                    $lockedLog->update([
                        'status' => EscalationLog::STATUS_SKIPPED,
                        'completed_at' => now(),
                        'error' => match (true) {
                            ! $lockedRule->is_active => 'Rule is no longer active.',
                            ! $preview['matched'] => 'Trigger or filters no longer match.',
                            default => 'Trigger window changed before the rule could be applied.',
                        },
                    ]);

                    return 'skipped';
                }

                $actions = $lockedRule->getAttribute('actions');
                if (! is_array($actions)) {
                    throw new \RuntimeException('Escalation actions must be an array.');
                }

                foreach (array_values($actions) as $index => $action) {
                    if (! is_array($action)) {
                        throw new \RuntimeException('Each escalation action must be an object.');
                    }
                    $actionType = (string) ($action['type'] ?? '');

                    try {
                        $context = $this->actionExecutor->execute($lockedRule, $lockedTicket, $action);
                    } catch (Throwable $exception) {
                        throw new EscalationActionException($index + 1, $actionType, $exception);
                    }

                    EscalationActionLog::create([
                        'escalation_log_id' => $lockedLog->id,
                        'escalation_rule_id' => $lockedRule->id,
                        'ticket_id' => $lockedTicket->id,
                        'attempt' => $attempt,
                        'action_order' => $index + 1,
                        'action_type' => $actionType,
                        'status' => 'applied',
                        'actor' => 'system',
                        'before_context' => $context['before'],
                        'after_context' => $context['after'],
                        'occurred_at' => now(),
                    ]);
                    $lockedTicket->refresh();
                }

                $lockedLog->update([
                    'status' => EscalationLog::STATUS_APPLIED,
                    'completed_at' => now(),
                    'error' => null,
                ]);

                return 'applied';
            }, 3);
        } catch (Throwable $exception) {
            EscalationLog::query()->whereKey($log->id)->update([
                'status' => EscalationLog::STATUS_FAILED,
                'completed_at' => now(),
                'error' => Str::limit($exception->getMessage(), 2000, ''),
                'updated_at' => now(),
            ]);

            if ($exception instanceof EscalationActionException) {
                EscalationActionLog::create([
                    'escalation_log_id' => $log->id,
                    'escalation_rule_id' => $rule->id,
                    'ticket_id' => $ticket->id,
                    'attempt' => $attempt,
                    'action_order' => $exception->actionOrder,
                    'action_type' => $exception->actionType,
                    'status' => 'failed',
                    'actor' => 'system',
                    'error' => Str::limit($exception->getMessage(), 2000, ''),
                    'occurred_at' => now(),
                ]);
            }

            return 'failed';
        }
    }

    private function claim(EscalationLog $log): ?int
    {
        return DB::transaction(function () use ($log): ?int {
            $log = EscalationLog::query()->lockForUpdate()->findOrFail($log->id);

            if (in_array($log->status, [EscalationLog::STATUS_APPLIED, EscalationLog::STATUS_SKIPPED], true)) {
                return null;
            }
            if ($log->status === EscalationLog::STATUS_PROCESSING
                && $log->started_at !== null
                && Carbon::parse($log->started_at)->isAfter(now()->subMinutes(10))) {
                return null;
            }

            $attempt = $log->attempts + 1;
            $log->update([
                'status' => EscalationLog::STATUS_PROCESSING,
                'attempts' => $attempt,
                'started_at' => now(),
                'completed_at' => null,
                'error' => null,
            ]);

            return $attempt;
        }, 3);
    }
}
