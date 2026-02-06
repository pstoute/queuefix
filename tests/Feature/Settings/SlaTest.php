<?php

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, put, delete};

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('listing SLA policies', function () {
    actingAs($this->admin);

    SlaPolicy::factory()->count(3)->create();

    get(route('settings.sla.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Sla/Index')
            ->has('slaPolicies', 3)
        );
});

test('creating SLA policy', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'name' => 'Standard SLA',
        'priority' => TicketPriority::Normal->value,
        'first_response_hours' => 4,
        'resolution_hours' => 24,
        'is_active' => true,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('sla_policies', [
        'name' => 'Standard SLA',
        'priority' => TicketPriority::Normal->value,
        'first_response_hours' => 4,
        'resolution_hours' => 24,
        'is_active' => true,
    ]);
});

test('creating urgent SLA policy', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'name' => 'Urgent SLA',
        'priority' => TicketPriority::Urgent->value,
        'first_response_hours' => 1,
        'resolution_hours' => 4,
        'is_active' => true,
    ])
        ->assertRedirect();

    $this->assertDatabaseHas('sla_policies', [
        'name' => 'Urgent SLA',
        'priority' => TicketPriority::Urgent->value,
        'first_response_hours' => 1,
        'resolution_hours' => 4,
    ]);
});

test('updating SLA policy', function () {
    actingAs($this->admin);

    $policy = SlaPolicy::factory()->create();

    put(route('settings.sla.update', $policy), [
        'name' => 'Updated SLA',
        'priority' => $policy->priority->value,
        'first_response_hours' => 8,
        'resolution_hours' => 48,
        'is_active' => false,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('sla_policies', [
        'id' => $policy->id,
        'name' => 'Updated SLA',
        'first_response_hours' => 8,
        'resolution_hours' => 48,
        'is_active' => false,
    ]);
});

test('deleting SLA policy', function () {
    actingAs($this->admin);

    $policy = SlaPolicy::factory()->create();

    delete(route('settings.sla.destroy', $policy))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('sla_policies', [
        'id' => $policy->id,
    ]);
});

test('SLA policy name is required', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'priority' => TicketPriority::Normal->value,
        'first_response_hours' => 4,
        'resolution_hours' => 24,
    ])
        ->assertSessionHasErrors('name');
});

test('SLA policy priority is required', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'name' => 'Test SLA',
        'first_response_hours' => 4,
        'resolution_hours' => 24,
    ])
        ->assertSessionHasErrors('priority');
});

test('SLA policy first response hours is required', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'name' => 'Test SLA',
        'priority' => TicketPriority::Normal->value,
        'resolution_hours' => 24,
    ])
        ->assertSessionHasErrors('first_response_hours');
});

test('SLA policy resolution hours is required', function () {
    actingAs($this->admin);

    post(route('settings.sla.store'), [
        'name' => 'Test SLA',
        'priority' => TicketPriority::Normal->value,
        'first_response_hours' => 4,
    ])
        ->assertSessionHasErrors('resolution_hours');
});
