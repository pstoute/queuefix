<?php

use App\Enums\MessageType;
use App\Enums\TicketUpdateEvent;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\TicketUpdatedNotification;
use App\Services\TicketNotificationService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

test('authorized active agents can watch and unwatch a ticket idempotently', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();

    actingAs($agent);

    post(route('agent.tickets.watch.store', $ticket))->assertRedirect();
    post(route('agent.tickets.watch.store', $ticket))->assertRedirect();

    $this->assertDatabaseCount('ticket_watchers', 1);
    $this->assertDatabaseHas('ticket_watchers', [
        'ticket_id' => $ticket->id,
        'user_id' => $agent->id,
    ]);

    delete(route('agent.tickets.watch.destroy', $ticket))->assertRedirect();
    $this->assertDatabaseCount('ticket_watchers', 0);
});

test('disabled agents cannot change watcher state', function () {
    $agent = User::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create();
    $ticket->watchers()->attach($agent);

    actingAs($agent);

    post(route('agent.tickets.watch.store', $ticket))->assertForbidden();
    delete(route('agent.tickets.watch.destroy', $ticket))->assertForbidden();
    $this->assertDatabaseCount('ticket_watchers', 1);
});

test('customer authentication cannot access agent watcher routes', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    auth('customer')->login($customer);

    post(route('agent.tickets.watch.store', $ticket))->assertRedirect(route('login'));
    $this->assertDatabaseCount('ticket_watchers', 0);
});

test('ticket page exposes active watchers and current watcher state', function () {
    $agent = User::factory()->create();
    $otherWatcher = User::factory()->create();
    $disabledWatcher = User::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create();
    $ticket->watchers()->attach([$agent->id, $otherWatcher->id, $disabledWatcher->id]);

    actingAs($agent);

    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.is_watching', true)
            ->has('ticket.watchers', 2)
            ->where('ticket.watchers.0.name', collect([$agent, $otherWatcher])->sortBy('name')->first()->name)
        );
});

test('watching inbox filter only returns tickets watched by the current agent', function () {
    $agent = User::factory()->create();
    $watched = Ticket::factory()->create();
    $unwatched = Ticket::factory()->create();
    $watched->watchers()->attach($agent);

    actingAs($agent);

    get(route('agent.tickets.index', ['watching' => '1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.watching', '1')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $watched->id)
        );
});

test('automatic watching is disabled by default', function () {
    Notification::fake();
    config()->set('tickets.auto_watch.creator', false);
    config()->set('tickets.auto_watch.assignee', false);

    $creator = User::factory()->create();
    $assignee = User::factory()->create();

    actingAs($creator);

    post(route('agent.tickets.store'), [
        'subject' => 'Manual subscriptions only',
        'body' => 'Do not create implicit watchers.',
        'assigned_to' => $assignee->id,
        'customer_email' => 'manual@example.com',
        'customer_name' => 'Manual Customer',
    ])->assertRedirect();

    $this->assertDatabaseCount('ticket_watchers', 0);
});

test('configured auto-watch policy subscribes active creators and assignees', function () {
    Notification::fake();
    config()->set('tickets.auto_watch.creator', true);
    config()->set('tickets.auto_watch.assignee', true);

    $creator = User::factory()->create();
    $assignee = User::factory()->create();

    actingAs($creator);

    post(route('agent.tickets.store'), [
        'subject' => 'Configured subscriptions',
        'body' => 'Subscribe both participants.',
        'assigned_to' => $assignee->id,
        'customer_email' => 'configured@example.com',
        'customer_name' => 'Configured Customer',
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Configured subscriptions')->sole();
    expect($ticket->watchers()->pluck('users.id')->all())
        ->toContain($creator->id, $assignee->id);
    Notification::assertNothingSent();
});

test('public customer replies notify a watcher-assignee only once', function () {
    Notification::fake();

    $recipient = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $recipient->id,
    ]);
    $ticket->watchers()->attach($recipient);

    actingAs($customer, 'customer');

    post(route('customer.tickets.reply', $ticket), ['body' => 'Customer follow-up'])
        ->assertRedirect();

    Notification::assertSentTo(
        $recipient,
        TicketUpdatedNotification::class,
        fn (TicketUpdatedNotification $notification) => $notification->event === TicketUpdateEvent::CustomerReply,
    );
    expect(Notification::sent($recipient, TicketUpdatedNotification::class))->toHaveCount(1);
});

test('ticket update notifications are durably stored without message body content', function () {
    $watcher = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $ticket->watchers()->attach($watcher);

    app(TicketService::class)->addMessage($ticket, [
        'type' => MessageType::Reply,
        'body_text' => 'Sensitive customer text',
        'body_html' => '<p>Sensitive customer text</p>',
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
    ]);

    $notification = $watcher->notifications()->sole();

    expect($notification->data)
        ->event->toBe(TicketUpdateEvent::CustomerReply->value)
        ->ticket_id->toBe($ticket->id)
        ->not->toHaveKey('body');
});

test('staff replies suppress the actor and do not notify for internal notes', function () {
    Notification::fake();

    $actor = User::factory()->create();
    $watcher = User::factory()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $actor->id]);
    $ticket->watchers()->attach([$actor->id, $watcher->id]);

    actingAs($actor);

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Public staff reply',
        'type' => MessageType::Reply->value,
    ])->assertRedirect();

    Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
    Notification::assertSentTo(
        $watcher,
        TicketUpdatedNotification::class,
        fn (TicketUpdatedNotification $notification) => $notification->event === TicketUpdateEvent::StaffReply,
    );

    Notification::fake();

    post(route('agent.tickets.reply', $ticket), [
        'body' => 'Private note',
        'type' => MessageType::InternalNote->value,
    ])->assertRedirect();

    Notification::assertNothingSent();
});

test('status and assignment changes notify active recipients with the matching event', function () {
    Notification::fake();

    $actor = User::factory()->create();
    $watcher = User::factory()->create();
    $assignee = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $ticket->watchers()->attach($watcher);

    actingAs($actor);

    $newStatus = TicketStatus::query()->whereNull('is_default')->where('is_closed', false)->firstOrFail();
    patch(route('agent.tickets.status', $ticket), ['status' => $newStatus->slug])->assertRedirect();

    Notification::assertSentTo(
        $watcher,
        TicketUpdatedNotification::class,
        fn (TicketUpdatedNotification $notification) => $notification->event === TicketUpdateEvent::StatusChanged,
    );

    Notification::fake();

    patch(route('agent.tickets.assign', $ticket), ['assigned_to' => $assignee->id])->assertRedirect();

    Notification::assertSentTo(
        [$watcher, $assignee],
        TicketUpdatedNotification::class,
        fn (TicketUpdatedNotification $notification) => $notification->event === TicketUpdateEvent::AssignmentChanged,
    );
    Notification::assertNotSentTo($actor, TicketUpdatedNotification::class);
});

test('escalations can notify watchers through the shared notification path', function () {
    Notification::fake();

    $watcher = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $ticket->watchers()->attach($watcher);

    app(TicketNotificationService::class)->notifyEscalated($ticket, details: ['level' => 1]);

    Notification::assertSentTo(
        $watcher,
        TicketUpdatedNotification::class,
        fn (TicketUpdatedNotification $notification) => $notification->event === TicketUpdateEvent::Escalated,
    );
});

test('disabled and deleted watchers are not notified', function () {
    Notification::fake();

    $disabled = User::factory()->create(['is_active' => false]);
    $deleted = User::factory()->create();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $ticket->watchers()->attach([$disabled->id, $deleted->id]);
    $deleted->delete();

    app(TicketService::class)->addMessage($ticket, [
        'type' => MessageType::Reply,
        'body_text' => 'A new customer reply',
        'body_html' => '<p>A new customer reply</p>',
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
    ]);

    Notification::assertNothingSent();
    $this->assertDatabaseMissing('ticket_watchers', ['user_id' => $deleted->id]);
});
