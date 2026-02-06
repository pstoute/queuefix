<?php

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use function Pest\Laravel\{actingAs, get, post, patch};

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->user = User::factory()->create();
});

test('ticket index page renders for authenticated user', function () {
    actingAs($this->user);

    get(route('agent.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->has('tickets')
            ->has('filters')
            ->has('agents')
            ->has('counts')
        );
});

test('ticket index returns 302 for unauthenticated user', function () {
    get(route('agent.tickets.index'))
        ->assertStatus(302)
        ->assertRedirect(route('login'));
});

test('ticket list filtering by status', function () {
    actingAs($this->user);

    $openTicket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    $pendingTicket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    get(route('agent.tickets.index', ['status' => TicketStatus::Open->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->where('filters.status', TicketStatus::Open->value)
            ->has('tickets.data', 1)
        );
});

test('ticket list filtering by priority', function () {
    actingAs($this->user);

    $normalTicket = Ticket::factory()->create(['priority' => TicketPriority::Normal]);
    $urgentTicket = Ticket::factory()->urgent()->create();

    get(route('agent.tickets.index', ['priority' => TicketPriority::Urgent->value]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->where('filters.priority', TicketPriority::Urgent->value)
            ->has('tickets.data', 1)
        );
});

test('ticket list filtering by assignee', function () {
    actingAs($this->user);

    $agent = User::factory()->create();
    $assignedTicket = Ticket::factory()->create(['assigned_to' => $agent->id]);
    $unassignedTicket = Ticket::factory()->create(['assigned_to' => null]);

    get(route('agent.tickets.index', ['assigned_to' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->where('filters.assigned_to', $agent->id)
            ->has('tickets.data', 1)
        );
});

test('ticket list filtering by unassigned', function () {
    actingAs($this->user);

    $agent = User::factory()->create();
    $assignedTicket = Ticket::factory()->create(['assigned_to' => $agent->id]);
    $unassignedTicket = Ticket::factory()->create(['assigned_to' => null]);

    get(route('agent.tickets.index', ['assigned_to' => 'unassigned']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->where('filters.assigned_to', 'unassigned')
            ->has('tickets.data', 1)
        );
});

test('ticket list search by subject', function () {
    actingAs($this->user);

    $ticket1 = Ticket::factory()->create(['subject' => 'Password reset issue']);
    $ticket2 = Ticket::factory()->create(['subject' => 'Billing question']);

    get(route('agent.tickets.index', ['search' => 'password']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Index')
            ->where('filters.search', 'password')
            ->has('tickets.data', 1)
        );
});

test('ticket list search by ticket number', function () {
    actingAs($this->user);

    $ticket1 = Ticket::factory()->create(['ticket_number' => 'QF-1']);
    $ticket2 = Ticket::factory()->create(['ticket_number' => 'QF-2']);

    get(route('agent.tickets.index', ['search' => 'QF-1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
        );
});

test('ticket list search by customer name', function () {
    actingAs($this->user);

    $customer1 = Customer::factory()->create(['name' => 'John Doe']);
    $customer2 = Customer::factory()->create(['name' => 'Jane Smith']);
    $ticket1 = Ticket::factory()->create(['customer_id' => $customer1->id]);
    $ticket2 = Ticket::factory()->create(['customer_id' => $customer2->id]);

    get(route('agent.tickets.index', ['search' => 'John']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
        );
});

test('ticket creation page renders', function () {
    actingAs($this->user);

    get(route('agent.tickets.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Create')
            ->has('agents')
            ->has('priorities')
        );
});

test('creating a new ticket', function () {
    actingAs($this->user);

    $response = post(route('agent.tickets.store'), [
        'subject' => 'Test ticket',
        'body' => 'This is a test ticket body',
        'priority' => TicketPriority::Normal->value,
        'customer_email' => 'customer@example.com',
        'customer_name' => 'Test Customer',
    ]);

    $response->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'subject' => 'Test ticket',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Normal->value,
    ]);

    $this->assertDatabaseHas('customers', [
        'email' => 'customer@example.com',
        'name' => 'Test Customer',
    ]);

    $this->assertDatabaseHas('messages', [
        'body_text' => 'This is a test ticket body',
        'type' => MessageType::Reply->value,
    ]);
});

test('creating a ticket with existing customer', function () {
    actingAs($this->user);

    $customer = Customer::factory()->create(['email' => 'existing@example.com']);

    post(route('agent.tickets.store'), [
        'subject' => 'Test ticket',
        'body' => 'Test body',
        'customer_email' => 'existing@example.com',
        'customer_name' => 'Different Name',
    ]);

    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('tickets', [
        'customer_id' => $customer->id,
    ]);
});

test('viewing a ticket detail', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();

    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Agent/Tickets/Show')
            ->has('ticket')
            ->has('agents')
            ->has('statuses')
            ->has('priorities')
            ->where('ticket.id', $ticket->id)
        );
});

test('replying to a ticket', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();
    $lastActivity = $ticket->last_activity_at;

    sleep(1);

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'This is a reply',
        'type' => 'reply',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('messages', [
        'ticket_id' => $ticket->id,
        'body_text' => 'This is a reply',
        'type' => MessageType::Reply->value,
        'sender_type' => User::class,
        'sender_id' => $this->user->id,
    ]);

    $ticket->refresh();
    expect($ticket->last_activity_at)->toBeGreaterThan($lastActivity);
});

test('adding an internal note', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'This is an internal note',
        'type' => 'internal_note',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Note added.');

    $this->assertDatabaseHas('messages', [
        'ticket_id' => $ticket->id,
        'body_text' => 'This is an internal note',
        'type' => MessageType::InternalNote->value,
        'sender_type' => User::class,
        'sender_id' => $this->user->id,
    ]);
});

test('changing ticket status', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);

    patch(route('agent.tickets.status', $ticket), [
        'status' => TicketStatus::Resolved->value,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'id' => $ticket->id,
        'status' => TicketStatus::Resolved->value,
    ]);
});

test('assigning a ticket to agent', function () {
    actingAs($this->user);

    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => null]);

    patch(route('agent.tickets.assign', $ticket), [
        'assigned_to' => $agent->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'id' => $ticket->id,
        'assigned_to' => $agent->id,
    ]);
});

test('unassigning a ticket', function () {
    actingAs($this->user);

    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $agent->id]);

    patch(route('agent.tickets.assign', $ticket), [
        'assigned_to' => null,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tickets', [
        'id' => $ticket->id,
        'assigned_to' => null,
    ]);
});

test('merging tickets moves messages', function () {
    actingAs($this->user);

    $primaryTicket = Ticket::factory()->create();
    $secondaryTicket = Ticket::factory()->create();

    $message1 = \App\Models\Message::factory()->create(['ticket_id' => $secondaryTicket->id]);
    $message2 = \App\Models\Message::factory()->create(['ticket_id' => $secondaryTicket->id]);

    post(route('agent.tickets.merge', $primaryTicket), [
        'merge_ticket_id' => $secondaryTicket->id,
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('messages', [
        'id' => $message1->id,
        'ticket_id' => $primaryTicket->id,
    ]);

    $this->assertDatabaseHas('messages', [
        'id' => $message2->id,
        'ticket_id' => $primaryTicket->id,
    ]);

    $this->assertDatabaseHas('tickets', [
        'id' => $secondaryTicket->id,
        'status' => TicketStatus::Closed->value,
    ]);
});

test('merging tickets syncs tags', function () {
    actingAs($this->user);

    $primaryTicket = Ticket::factory()->create();
    $secondaryTicket = Ticket::factory()->create();

    $tag1 = Tag::factory()->create();
    $tag2 = Tag::factory()->create();
    $tag3 = Tag::factory()->create();

    $primaryTicket->tags()->attach([$tag1->id, $tag2->id]);
    $secondaryTicket->tags()->attach([$tag2->id, $tag3->id]);

    post(route('agent.tickets.merge', $primaryTicket), [
        'merge_ticket_id' => $secondaryTicket->id,
    ])
        ->assertRedirect();

    $primaryTicket->refresh();

    expect($primaryTicket->tags)->toHaveCount(3);
    expect($primaryTicket->tags->pluck('id')->toArray())->toContain($tag1->id, $tag2->id, $tag3->id);
});

test('cannot merge ticket with itself', function () {
    actingAs($this->user);

    $ticket = Ticket::factory()->create();

    post(route('agent.tickets.merge', $ticket), [
        'merge_ticket_id' => $ticket->id,
    ])
        ->assertSessionHasErrors('merge_ticket_id');
});
