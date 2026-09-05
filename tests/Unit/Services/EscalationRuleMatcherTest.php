<?php

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Models\Department;
use App\Models\EscalationRule;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SlaTimer;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EscalationRuleMatcher;
use Illuminate\Support\Carbon;

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

test('time triggers match their exact threshold and reject a completed first response', function () {
    $ticket = Ticket::factory()->create([
        'created_at' => now()->subMinutes(60),
        'updated_at' => now()->subMinutes(60),
        'last_activity_at' => now()->subMinutes(45),
    ]);
    $firstResponseRule = ($this->makeRule)([
        'trigger' => EscalationRule::TRIGGER_NO_FIRST_RESPONSE,
        'trigger_config' => ['minutes' => 60],
    ]);
    $activityRule = ($this->makeRule)([
        'name' => 'Inactive ticket',
        'trigger' => EscalationRule::TRIGGER_NO_ACTIVITY,
        'trigger_config' => ['minutes' => 45],
    ]);
    $matcher = app(EscalationRuleMatcher::class);

    expect($matcher->preview($firstResponseRule, $ticket)['matched'])->toBeTrue()
        ->and($matcher->preview($activityRule, $ticket)['matched'])->toBeTrue();

    Message::factory()->fromAgent()->create([
        'ticket_id' => $ticket->id,
        'type' => MessageType::Reply,
    ]);

    expect($matcher->preview($firstResponseRule, $ticket->fresh())['matched'])->toBeFalse();
});

test('SLA approaching and breached triggers support individual clocks', function () {
    $approachingTicket = Ticket::factory()->create();
    SlaTimer::factory()->create([
        'ticket_id' => $approachingTicket->id,
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
        'first_response_due_at' => now()->addMinutes(5),
        'resolution_due_at' => now()->addHours(5),
    ]);
    $breachedTicket = Ticket::factory()->create();
    SlaTimer::factory()->breachedFirstResponse()->create([
        'ticket_id' => $breachedTicket->id,
        'resolution_due_at' => now()->addHours(5),
    ]);
    $approachingRule = ($this->makeRule)([
        'trigger' => EscalationRule::TRIGGER_SLA_APPROACHING,
        'trigger_config' => ['clock' => 'first_response'],
    ]);
    $breachedRule = ($this->makeRule)([
        'name' => 'SLA breached',
        'trigger' => EscalationRule::TRIGGER_SLA_BREACHED,
        'trigger_config' => ['clock' => 'first_response'],
    ]);
    $matcher = app(EscalationRuleMatcher::class);

    expect($matcher->preview($approachingRule, $approachingTicket->fresh())['matched'])->toBeTrue()
        ->and($matcher->preview($breachedRule, $breachedTicket->fresh())['matched'])->toBeTrue()
        ->and($matcher->preview($breachedRule, $approachingTicket->fresh())['matched'])->toBeFalse();
});

test('status-entry and priority-change triggers use explicit transition timestamps', function () {
    $ticket = Ticket::factory()->highPriority()->create([
        'status_changed_at' => now()->subMinute(),
        'priority_changed_at' => now()->subMinutes(2),
    ]);
    $statusRule = ($this->makeRule)([
        'trigger' => EscalationRule::TRIGGER_STATUS_ENTERED,
        'trigger_config' => ['status_id' => $ticket->ticket_status_id],
    ]);
    $priorityRule = ($this->makeRule)([
        'name' => 'High priority',
        'trigger' => EscalationRule::TRIGGER_PRIORITY_CHANGED,
        'trigger_config' => ['priority' => TicketPriority::High->value],
    ]);
    $matcher = app(EscalationRuleMatcher::class);

    $statusPreview = $matcher->preview($statusRule, $ticket);
    $priorityPreview = $matcher->preview($priorityRule, $ticket);

    expect($statusPreview['matched'])->toBeTrue()
        ->and($statusPreview['trigger_window'])->toContain('2026-09-04T11:59:00')
        ->and($priorityPreview['matched'])->toBeTrue()
        ->and($priorityPreview['trigger_window'])->toContain('2026-09-04T11:58:00');

    $ticket->update(['priority_changed_at' => null]);
    expect($matcher->preview($priorityRule, $ticket->fresh())['matched'])->toBeFalse();
});

test('all configured filters must match and closed tickets require explicit inclusion', function () {
    $agent = User::factory()->create();
    $department = Department::create(['name' => 'Priority Support']);
    $mailbox = Mailbox::factory()->create(['department_id' => $department->id]);
    $tags = Tag::factory()->count(2)->create();
    $ticket = Ticket::factory()->urgent()->create([
        'assigned_to' => $agent->id,
        'department_id' => $department->id,
        'mailbox_id' => $mailbox->id,
        'last_activity_at' => now()->subHour(),
    ]);
    $ticket->tags()->attach($tags->modelKeys());
    $rule = ($this->makeRule)([
        'filters' => [
            'status_ids' => [$ticket->ticket_status_id],
            'priorities' => [TicketPriority::Urgent->value],
            'department_ids' => [$department->id],
            'assignee_state' => 'assigned',
            'mailbox_ids' => [$mailbox->id],
            'tag_ids' => $tags->modelKeys(),
        ],
    ]);
    $matcher = app(EscalationRuleMatcher::class);

    expect($matcher->preview($rule, $ticket)['matched'])->toBeTrue();

    $ticket->tags()->detach($tags->last()->id);
    $filtered = $matcher->preview($rule, $ticket->fresh());
    expect($filtered['matched'])->toBeFalse()
        ->and($filtered['filters_matched'])->toBeFalse();

    $closed = Ticket::factory()->closed()->create(['last_activity_at' => now()->subHour()]);
    expect($matcher->preview($rule->replicate()->fill(['filters' => []]), $closed)['eligible'])->toBeFalse();

    $rule->update(['filters' => [], 'include_closed' => true]);
    expect($matcher->preview($rule->fresh(), $closed)['matched'])->toBeTrue();
});
