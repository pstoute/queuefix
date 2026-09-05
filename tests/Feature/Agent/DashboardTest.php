<?php

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->user = User::factory()->create();
    $this->openStatus = TicketStatus::defaultStatus();
    $this->pendingStatus = $this->ticketStatusAt(20);
    $this->resolvedStatus = $this->ticketStatusAt(40);
});

test('dashboard renders with stats', function () {
    actingAs($this->user);

    Ticket::factory()->count(5)->create(['ticket_status_id' => $this->openStatus->id]);
    Ticket::factory()->count(3)->create(['ticket_status_id' => $this->pendingStatus->id]);
    Ticket::factory()->count(2)->create(['ticket_status_id' => $this->resolvedStatus->id]);

    get(route('agent.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Dashboard')
            ->has('statusCounts', 5)
            ->has('stats')
        );
});

test('dashboard shows correct counts', function () {
    actingAs($this->user);

    Ticket::factory()->count(5)->create(['ticket_status_id' => $this->openStatus->id]);
    Ticket::factory()->count(3)->create(['ticket_status_id' => $this->pendingStatus->id]);
    Ticket::factory()->count(2)->create(['assigned_to' => null, 'ticket_status_id' => $this->openStatus->id]);

    get(route('agent.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Dashboard')
            ->where('statusCounts.0.tickets_count', 7)
            ->where('statusCounts.1.tickets_count', 3)
            ->where('stats.unassigned', 10)
        );
});

test('dashboard redirects unauthenticated users', function () {
    get(route('agent.dashboard'))
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});
