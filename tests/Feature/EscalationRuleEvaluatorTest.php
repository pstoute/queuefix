<?php

use App\Enums\TicketPriority;
use App\Jobs\EvaluateEscalationRulesJob;
use App\Models\EscalationActionLog;
use App\Models\EscalationLog;
use App\Models\EscalationRule;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use App\Services\EscalationRuleEvaluator;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $this->makeRule = fn (array $attributes = []): EscalationRule => EscalationRule::create(array_merge([
        'name' => 'Test escalation',
        'trigger' => EscalationRule::TRIGGER_NO_ACTIVITY,
        'trigger_config' => ['minutes' => 30],
        'filters' => [],
        'actions' => [['type' => EscalationRule::ACTION_INTERNAL_NOTE, 'body' => 'Escalated.']],
        'include_closed' => false,
        'include_archived' => false,
        'is_active' => true,
    ], $attributes));
});

afterEach(fn () => Carbon::setTestNow());

test('ordered actions apply atomically and record system before-and-after audits', function () {
    Notification::fake();
    $agent = User::factory()->create();
    $removedTag = Tag::factory()->create();
    $addedTag = Tag::factory()->create();
    $newStatus = $this->ticketStatusAt(20);
    $ticket = Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    $ticket->tags()->attach($removedTag);
    $rule = ($this->makeRule)([
        'actions' => [
            ['type' => EscalationRule::ACTION_ASSIGN, 'user_id' => $agent->id],
            ['type' => EscalationRule::ACTION_PRIORITY, 'priority' => TicketPriority::High->value],
            ['type' => EscalationRule::ACTION_STATUS, 'status_id' => $newStatus->id],
            ['type' => EscalationRule::ACTION_INTERNAL_NOTE, 'body' => 'Automatically escalated.'],
            ['type' => EscalationRule::ACTION_REMOVE_TAG, 'tag_id' => $removedTag->id],
            ['type' => EscalationRule::ACTION_ADD_TAG, 'tag_id' => $addedTag->id],
            ['type' => EscalationRule::ACTION_NOTIFY, 'channel' => 'database', 'user_ids' => [$agent->id]],
        ],
    ]);

    $result = app(EscalationRuleEvaluator::class)->evaluate();

    expect($result['applied'])->toBe(1)
        ->and($ticket->fresh()->assigned_to)->toBe($agent->id)
        ->and($ticket->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::High->value)
        ->and($ticket->fresh()->ticket_status_id)->toBe($newStatus->id)
        ->and($ticket->messages()->where('body_text', 'Automatically escalated.')->exists())->toBeTrue()
        ->and($ticket->tags()->whereKey($removedTag->id)->exists())->toBeFalse()
        ->and($ticket->tags()->whereKey($addedTag->id)->exists())->toBeTrue();

    Notification::assertSentTo($agent, TicketEscalatedNotification::class);

    $log = EscalationLog::query()->sole();
    expect($log->status)->toBe(EscalationLog::STATUS_APPLIED)
        ->and($log->actor)->toBe('system')
        ->and($log->attempts)->toBe(1)
        ->and($log->actionLogs()->pluck('action_order')->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($log->actionLogs()->pluck('actor')->unique()->all())->toBe(['system'])
        ->and($log->actionLogs()->whereNull('before_context')->exists())->toBeFalse()
        ->and($log->actionLogs()->whereNull('after_context')->exists())->toBeFalse();

    $priorityAudit = $log->actionLogs()->where('action_type', EscalationRule::ACTION_PRIORITY)->sole();
    expect($priorityAudit->escalation_rule_id)->toBe($rule->id)
        ->and($priorityAudit->before_context)->toBe(['priority' => TicketPriority::Normal->value])
        ->and($priorityAudit->after_context)->toBe(['priority' => TicketPriority::High->value])
        ->and($priorityAudit->occurred_at)->not->toBeNull();
});

test('the same trigger window is idempotent across repeated evaluator runs', function () {
    Notification::fake();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    ($this->makeRule)([
        'actions' => [[
            'type' => EscalationRule::ACTION_NOTIFY,
            'channel' => 'database',
            'user_ids' => [$agent->id],
        ]],
    ]);
    $evaluator = app(EscalationRuleEvaluator::class);

    expect($evaluator->evaluate()['applied'])->toBe(1)
        ->and($evaluator->evaluate()['applied'])->toBe(0)
        ->and(EscalationLog::query()->count())->toBe(1)
        ->and(EscalationActionLog::query()->where('status', 'applied')->count())->toBe(1);
    Notification::assertSentToTimes($agent, TicketEscalatedNotification::class, 1);

    $ticket->update(['last_activity_at' => now()->subHours(2)]);
    expect($evaluator->evaluate()['applied'])->toBe(1)
        ->and(EscalationLog::query()->count())->toBe(2);
});

test('a claimed run is skipped if its trigger window changes before actions execute', function () {
    $ticket = Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    $rule = ($this->makeRule)();
    $claimedWindow = $ticket->last_activity_at->toIso8601String();
    $ticket->update(['last_activity_at' => now()->subHours(2)]);

    $result = app(EscalationRuleEvaluator::class)->apply($rule, $ticket, $claimedWindow, []);

    expect($result)->toBe('skipped')
        ->and(EscalationLog::query()->sole()->status)->toBe(EscalationLog::STATUS_SKIPPED)
        ->and(EscalationActionLog::query()->count())->toBe(0)
        ->and($ticket->messages()->where('body_text', 'Escalated.')->exists())->toBeFalse();
});

test('failed batches roll back, remain visible, and safely retry the same log', function () {
    $inactiveAgent = User::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    ($this->makeRule)([
        'actions' => [
            ['type' => EscalationRule::ACTION_PRIORITY, 'priority' => TicketPriority::Urgent->value],
            ['type' => EscalationRule::ACTION_ASSIGN, 'user_id' => $inactiveAgent->id],
        ],
    ]);
    $evaluator = app(EscalationRuleEvaluator::class);

    expect($evaluator->evaluate()['failed'])->toBe(1)
        ->and($ticket->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::Normal->value);

    $log = EscalationLog::query()->sole();
    expect($log->status)->toBe(EscalationLog::STATUS_FAILED)
        ->and($log->attempts)->toBe(1)
        ->and($log->error)->not->toBeNull()
        ->and($log->actionLogs()->count())->toBe(1)
        ->and($log->actionLogs()->sole()->status)->toBe('failed')
        ->and($log->actionLogs()->sole()->action_order)->toBe(2);

    $inactiveAgent->update(['is_active' => true]);
    expect($evaluator->evaluate()['applied'])->toBe(1)
        ->and($ticket->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::Urgent->value);

    $log->refresh();
    expect($log->status)->toBe(EscalationLog::STATUS_APPLIED)
        ->and($log->attempts)->toBe(2)
        ->and($log->actionLogs()->where('attempt', 1)->where('status', 'failed')->count())->toBe(1)
        ->and($log->actionLogs()->where('attempt', 2)->where('status', 'applied')->count())->toBe(2);
});

test('a failed rule does not prevent later rules and unsent notifications are never applied', function () {
    $tag = Tag::factory()->create();
    Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    ($this->makeRule)([
        'name' => 'No recipient',
        'created_at' => now()->subMinute(),
        'actions' => [['type' => EscalationRule::ACTION_NOTIFY, 'channel' => 'database']],
    ]);
    ($this->makeRule)([
        'name' => 'Later valid rule',
        'created_at' => now(),
        'actions' => [['type' => EscalationRule::ACTION_ADD_TAG, 'tag_id' => $tag->id]],
    ]);

    $result = app(EscalationRuleEvaluator::class)->evaluate();

    expect($result['failed'])->toBe(1)
        ->and($result['applied'])->toBe(1)
        ->and(EscalationActionLog::query()->where('action_type', EscalationRule::ACTION_NOTIFY)->where('status', 'applied')->exists())->toBeFalse()
        ->and(EscalationActionLog::query()->where('action_type', EscalationRule::ACTION_NOTIFY)->where('status', 'failed')->exists())->toBeTrue()
        ->and(Ticket::query()->sole()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

test('closed and archived tickets require explicit safe opt-in', function () {
    Notification::fake();
    $recipient = User::factory()->create();
    $archiveTag = Tag::factory()->create();
    $closed = Ticket::factory()->closed()->create(['last_activity_at' => now()->subHour()]);
    $archived = Ticket::factory()->create([
        'last_activity_at' => now()->subHour(),
        'merged_into_ticket_id' => Ticket::factory()->create()->id,
    ]);
    $archived->tags()->attach($archiveTag);
    $rule = ($this->makeRule)([
        'actions' => [['type' => EscalationRule::ACTION_PRIORITY, 'priority' => TicketPriority::High->value]],
    ]);
    $evaluator = app(EscalationRuleEvaluator::class);

    expect($evaluator->evaluate()['applied'])->toBe(0)
        ->and(EscalationLog::query()->count())->toBe(0);

    $rule->update(['include_closed' => true]);
    expect($evaluator->evaluate()['applied'])->toBe(1)
        ->and($closed->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::High->value)
        ->and($archived->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::Normal->value);

    ($this->makeRule)([
        'name' => 'Notify archived ticket',
        'filters' => ['tag_ids' => [$archiveTag->id]],
        'include_closed' => true,
        'include_archived' => true,
        'actions' => [[
            'type' => EscalationRule::ACTION_NOTIFY,
            'channel' => 'database',
            'user_ids' => [$recipient->id],
        ]],
    ]);

    expect($evaluator->evaluate()['applied'])->toBe(1)
        ->and($archived->fresh()->getRawOriginal('priority'))->toBe(TicketPriority::Normal->value);
    Notification::assertSentTo($recipient, TicketEscalatedNotification::class);
});

test('the queued evaluator and scheduler both declare overlap protection', function () {
    $middleware = (new EvaluateEscalationRulesJob)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);

    artisan('schedule:list')->expectsOutputToContain('evaluate-escalation-rules')->assertSuccessful();
});

test('escalation action audit rows are immutable', function () {
    $ticket = Ticket::factory()->create(['last_activity_at' => now()->subHour()]);
    ($this->makeRule)();
    app(EscalationRuleEvaluator::class)->evaluate();
    $action = EscalationActionLog::query()->sole();

    expect(fn () => $action->update(['actor' => 'user']))
        ->toThrow(LogicException::class)
        ->and(fn () => $action->delete())
        ->toThrow(LogicException::class);
});
