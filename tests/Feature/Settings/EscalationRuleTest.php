<?php

use App\Models\EscalationRule;
use App\Models\Ticket;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->payload = [
        'name' => 'Idle urgent ticket',
        'trigger' => EscalationRule::TRIGGER_NO_ACTIVITY,
        'trigger_config' => ['minutes' => 30],
        'filters' => [
            'priorities' => ['urgent'],
            'assignee_state' => 'unassigned',
        ],
        'actions' => [
            ['type' => EscalationRule::ACTION_INTERNAL_NOTE, 'body' => 'Automatically escalated.'],
            ['type' => EscalationRule::ACTION_NOTIFY, 'channel' => 'database'],
        ],
        'include_closed' => false,
        'include_archived' => false,
    ];
});

test('admins can create inactive rules and inspect the management page', function () {
    actingAs($this->admin);

    post(route('settings.escalations.store'), $this->payload)
        ->assertRedirect()
        ->assertSessionHas('success');

    $rule = EscalationRule::query()->sole();
    expect($rule->is_active)->toBeFalse()
        ->and($rule->created_by)->toBe($this->admin->id)
        ->and($rule->actions)->toHaveCount(2);

    get(route('settings.escalations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Escalations/Index')
            ->has('rules', 1)
            ->has('logs', 0)
            ->has('statuses')
            ->has('priorities', 4)
        );
});

test('a dry-run preview changes no ticket data and is required before activation', function () {
    actingAs($this->admin);
    $ticket = Ticket::factory()->urgent()->create([
        'last_activity_at' => now()->subHour(),
    ]);
    $rule = EscalationRule::create([
        ...$this->payload,
        'created_by' => $this->admin->id,
        'is_active' => false,
    ]);
    $originalUpdatedAt = $ticket->updated_at;

    patch(route('settings.escalations.active', $rule), ['is_active' => true])
        ->assertSessionHasErrors('is_active');

    post(route('settings.escalations.preview', $rule), ['ticket_id' => $ticket->id])
        ->assertRedirect()
        ->assertSessionHas('escalation_preview', fn (array $preview): bool => $preview['rule_id'] === $rule->id
            && $preview['ticket_id'] === $ticket->id
            && $preview['matched'] === true
            && count($preview['actions']) === 2
        );

    expect($ticket->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue()
        ->and($ticket->messages()->count())->toBe(0)
        ->and($rule->fresh()->last_previewed_at)->not->toBeNull();

    patch(route('settings.escalations.active', $rule), ['is_active' => true])
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($rule->fresh()->is_active)->toBeTrue();

    put(route('settings.escalations.update', $rule), [
        ...$this->payload,
        'name' => 'Updated rule',
    ])->assertRedirect();
    expect($rule->fresh()->is_active)->toBeFalse()
        ->and($rule->fresh()->last_previewed_at)->toBeNull();
});

test('rule input rejects arbitrary actions, unsupported fields, and invalid references', function () {
    actingAs($this->admin);

    post(route('settings.escalations.store'), [
        ...$this->payload,
        'actions' => [['type' => 'webhook', 'url' => 'https://example.com']],
    ])->assertSessionHasErrors('actions.0.type');

    post(route('settings.escalations.store'), [
        ...$this->payload,
        'trigger_config' => ['minutes' => 30, 'command' => 'dangerous'],
    ])->assertSessionHasErrors('trigger_config');

    post(route('settings.escalations.store'), [
        ...$this->payload,
        'actions' => [['type' => EscalationRule::ACTION_ASSIGN, 'user_id' => fake()->uuid()]],
    ])->assertSessionHasErrors('actions.0.user_id');

    post(route('settings.escalations.store'), [
        ...$this->payload,
        'include_archived' => true,
    ])->assertSessionHasErrors('include_archived');

    expect(EscalationRule::query()->count())->toBe(0);
});

test('non-admin agents cannot manage or preview escalation rules', function () {
    $agent = User::factory()->create();
    $rule = EscalationRule::create([
        ...$this->payload,
        'created_by' => $this->admin->id,
        'is_active' => false,
    ]);
    $ticket = Ticket::factory()->create();
    actingAs($agent);

    get(route('settings.escalations.index'))->assertForbidden();
    post(route('settings.escalations.store'), $this->payload)->assertForbidden();
    post(route('settings.escalations.preview', $rule), ['ticket_id' => $ticket->id])->assertForbidden();
    patch(route('settings.escalations.active', $rule), ['is_active' => true])->assertForbidden();
});
