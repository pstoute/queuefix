<?php

use App\Enums\MessageType;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketReadState;
use App\Models\User;
use App\Services\TicketReadStateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

afterEach(function () {
    Carbon::setTestNow();
});

test('opening a ticket marks its latest visible message read for that agent', function () {
    $agent = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'assigned_to' => $agent->id,
        'customer_id' => $customer->id,
    ]);
    $message = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
    ]);

    actingAs($agent);

    get(route('agent.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 1)
            ->where('tickets.data.0.unread_count', 1)
        );

    get(route('agent.tickets.show', $ticket))->assertOk();

    $this->assertDatabaseHas('ticket_read_states', [
        'ticket_id' => $ticket->id,
        'user_id' => $agent->id,
        'last_read_message_id' => $message->id,
    ]);

    get(route('agent.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 0)
            ->where('tickets.data.0.unread_count', 0)
        );
});

test('agents keep independent read cursors for the same ticket', function () {
    $firstAgent = User::factory()->create();
    $secondAgent = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'assigned_to' => $firstAgent->id,
        'customer_id' => $customer->id,
    ]);
    $ticket->watchers()->attach($secondAgent);

    $firstMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
        'created_at' => '2026-09-04 10:00:00',
        'updated_at' => '2026-09-04 10:00:00',
    ]);

    actingAs($firstAgent);
    get(route('agent.tickets.show', $ticket))->assertOk();

    $secondMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
        'created_at' => '2026-09-04 11:00:00',
        'updated_at' => '2026-09-04 11:00:00',
    ]);

    actingAs($secondAgent);
    get(route('agent.tickets.show', $ticket))->assertOk();

    expect(TicketReadState::query()
        ->where('ticket_id', $ticket->id)
        ->where('user_id', $firstAgent->id)
        ->value('last_read_message_id'))->toBe($firstMessage->id)
        ->and(TicketReadState::query()
            ->where('ticket_id', $ticket->id)
            ->where('user_id', $secondAgent->id)
            ->value('last_read_message_id'))->toBe($secondMessage->id)
        ->and(TicketReadState::query()->where('ticket_id', $ticket->id)->count())->toBe(2);

    actingAs($firstAgent);
    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $ticket->id)
            ->where('tickets.data.0.unread_count', 1)
        );
});

test('a customer reply creates unread work for assigned and watching agents', function () {
    $assignedAgent = User::factory()->create();
    $watcher = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'assigned_to' => $assignedAgent->id,
        'customer_id' => $customer->id,
    ]);
    $ticket->watchers()->attach($watcher);

    actingAs($assignedAgent);
    get(route('agent.tickets.show', $ticket))->assertOk();
    actingAs($watcher);
    get(route('agent.tickets.show', $ticket))->assertOk();

    auth('customer')->login($customer);
    post(route('customer.tickets.reply', $ticket), ['body' => 'A customer update'])->assertRedirect();

    actingAs($assignedAgent);
    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 1)
            ->has('tickets.data', 1)
            ->where('tickets.data.0.unread_count', 1)
        );

    actingAs($watcher);
    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 1)
            ->has('tickets.data', 1)
            ->where('tickets.data.0.unread_count', 1)
        );
});

test('a staff reply is unread for another relevant agent but never for its author', function () {
    $author = User::factory()->create();
    $otherAgent = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $author->id]);
    $ticket->watchers()->attach($otherAgent);

    actingAs($author);
    get(route('agent.tickets.show', $ticket))->assertOk();
    actingAs($otherAgent);
    get(route('agent.tickets.show', $ticket))->assertOk();

    actingAs($author);
    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Agent response',
        'type' => MessageType::Reply->value,
    ])->assertRedirect();

    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 0)
            ->has('tickets.data', 0)
        );

    actingAs($otherAgent);
    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('unreadCount', 1)
            ->has('tickets.data', 1)
            ->where('tickets.data.0.unread_count', 1)
        );
});

test('an older overlapping page request cannot move a newer read cursor backward', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $agent->id]);
    $olderMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'created_at' => '2026-09-04 10:00:00',
        'updated_at' => '2026-09-04 10:00:00',
    ]);
    $newerMessage = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'created_at' => '2026-09-04 11:00:00',
        'updated_at' => '2026-09-04 11:00:00',
    ]);
    $service = app(TicketReadStateService::class);

    Carbon::setTestNow('2026-09-04 12:00:00');
    $newerState = $service->markRead($ticket, $agent, $newerMessage);

    Carbon::setTestNow('2026-09-04 12:05:00');
    $result = $service->markRead($ticket, $agent, $olderMessage);

    expect($result->last_read_message_id)->toBe($newerMessage->id)
        ->and($result->last_read_at->equalTo($newerState->last_read_at))->toBeTrue()
        ->and(TicketReadState::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

test('assigned watching and unread inbox filters remain independently composable', function () {
    $agent = User::factory()->create();
    $customer = Customer::factory()->create();
    $assigned = Ticket::factory()->create(['assigned_to' => $agent->id]);
    $watched = Ticket::factory()->create();
    $unrelated = Ticket::factory()->create();
    $watched->watchers()->attach($agent);

    foreach ([$assigned, $watched, $unrelated] as $ticket) {
        Message::factory()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => Customer::class,
            'sender_id' => $customer->id,
        ]);
    }

    actingAs($agent);

    get(route('agent.tickets.index', ['assigned_to' => 'me']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.assigned_to', 'me')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $assigned->id)
        );

    get(route('agent.tickets.index', ['watching' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.watching', '1')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $watched->id)
        );

    get(route('agent.tickets.index', ['unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.unread', '1')
            ->where('unreadCount', 2)
            ->has('tickets.data', 2)
        );

    get(route('agent.tickets.index', ['watching' => '1', 'unread' => '1']))
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $watched->id)
        );
});

test('agents can explicitly mark an authorized ticket read while customers cannot', function () {
    $agent = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'assigned_to' => $agent->id,
        'customer_id' => $customer->id,
    ]);
    $message = Message::factory()->create([
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
    ]);

    auth('customer')->login($customer);
    patch(route('agent.tickets.read', $ticket))->assertRedirect(route('login'));
    $this->assertDatabaseCount('ticket_read_states', 0);

    actingAs($agent);
    patch(route('agent.tickets.read', $ticket))->assertRedirect();

    $this->assertDatabaseHas('ticket_read_states', [
        'ticket_id' => $ticket->id,
        'user_id' => $agent->id,
        'last_read_message_id' => $message->id,
    ]);
});

test('unread annotations use one query regardless of ticket count', function () {
    $agent = User::factory()->create();
    $customer = Customer::factory()->create();

    Ticket::factory()->count(12)->create(['assigned_to' => $agent->id])->each(
        fn (Ticket $ticket) => Message::factory()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => Customer::class,
            'sender_id' => $customer->id,
        ]),
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    $query = Ticket::query();
    app(TicketReadStateService::class)->addUnreadCount($query, $agent);
    $tickets = $query->get();

    expect($tickets)->toHaveCount(12)
        ->and($tickets->every(fn (Ticket $ticket) => $ticket->unread_count === 1))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(1);
});
