<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('dashboard renders with stats', function () {
    actingAs($this->user);

    Ticket::factory()->count(5)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Pending]);
    Ticket::factory()->count(2)->create(['status' => TicketStatus::Resolved]);

    get(route('agent.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Dashboard')
        );
});

test('dashboard shows correct counts', function () {
    actingAs($this->user);

    Ticket::factory()->count(5)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Pending]);
    Ticket::factory()->count(2)->create(['assigned_to' => null, 'status' => TicketStatus::Open]);

    get(route('agent.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Dashboard')
        );
});

test('dashboard redirects unauthenticated users', function () {
    get(route('agent.dashboard'))
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});
