<?php

use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\MagicLinkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
});

test('customer login page renders', function () {
    get(route('customer.login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customer/Auth/Login'));
});

test('customer can request magic link', function () {
    $customer = Customer::factory()->create(['email' => 'customer@example.com']);

    post(route('customer.login.send'), [
        'email' => 'customer@example.com',
    ])
        ->assertRedirect()
        ->assertSessionHas('status');
});

test('customer magic link creates customer if not exists', function () {
    post(route('customer.login.send'), [
        'email' => 'newcustomer@example.com',
    ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->assertDatabaseHas('customers', [
        'email' => 'newcustomer@example.com',
    ]);
});

test('customer can verify magic link', function () {
    $customer = Customer::factory()->create();
    $magicLink = app(MagicLinkService::class)->issueCustomer($customer);

    $verifyUrl = URL::temporarySignedRoute(
        'customer.auth.verify',
        $magicLink['expires_at'],
        ['customer' => $customer->id, 'token' => $magicLink['token']]
    );

    get($verifyUrl)
        ->assertRedirect(route('customer.tickets.index'));

    expect(auth()->guard('customer')->check())->toBeTrue();
    expect(auth()->guard('customer')->id())->toBe($customer->id);
});

test('customer magic link with invalid signature fails', function () {
    $customer = Customer::factory()->create();

    get(route('customer.auth.verify', ['customer' => $customer->id]))
        ->assertStatus(403);

    expect(auth()->guard('customer')->check())->toBeFalse();
});

test('customer can view their tickets', function () {
    $customer = Customer::factory()->create();
    $ownTicket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $otherTicket = Ticket::factory()->create();

    actingAs($customer, 'customer');

    get(route('customer.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/Tickets/Index')
            ->has('tickets.data', 1)
        );
});

test('customer ticket index omits staff assignment metadata', function () {
    $customer = Customer::factory()->create();
    $assignee = User::factory()->create([
        'name' => 'Assigned Administrator',
        'email' => 'assigned-administrator@example.com',
        'role' => UserRole::Admin,
    ]);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $assignee->id,
    ]);

    actingAs($customer, 'customer');

    get(route('customer.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/Tickets/Index')
            ->where('tickets.data.0.id', $ticket->id)
            ->missing('tickets.data.0.assigned_to')
            ->missing('tickets.data.0.assignee')
        );
});

test('customer cannot view other customers tickets', function () {
    $customer1 = Customer::factory()->create();
    $customer2 = Customer::factory()->create();
    $ticket1 = Ticket::factory()->create(['customer_id' => $customer1->id]);
    $ticket2 = Ticket::factory()->create(['customer_id' => $customer2->id]);

    actingAs($customer1, 'customer');

    get(route('customer.tickets.show', $ticket2))
        ->assertStatus(403);
});

test('customer can view their own ticket', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    actingAs($customer, 'customer');

    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/Tickets/Show')
            ->where('ticket.id', $ticket->id)
        );
});

test('customer ticket detail exposes only public participant identity', function () {
    $customer = Customer::factory()->create([
        'name' => 'Portal Customer',
        'avatar' => 'https://example.com/customer.png',
    ]);
    $agent = User::factory()->create([
        'name' => 'Support Agent',
        'email' => 'agent-secret@example.com',
        'avatar' => 'https://example.com/agent.png',
        'role' => UserRole::Agent,
        'is_active' => true,
    ]);
    $administrator = User::factory()->create([
        'name' => 'Support Administrator',
        'email' => 'administrator-secret@example.com',
        'avatar' => 'https://example.com/administrator.png',
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);
    $deletedStaff = User::factory()->create([
        'name' => 'Former Support Agent',
        'email' => 'former-agent@example.com',
    ]);
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $administrator->id,
    ]);

    $messages = [
        [Customer::class, $customer->id, 'Customer reply'],
        [User::class, $agent->id, 'Agent reply'],
        [User::class, $administrator->id, 'Administrator reply'],
        [User::class, $deletedStaff->id, 'Former staff reply'],
    ];

    foreach ($messages as $position => [$senderType, $senderId, $body]) {
        $message = Message::factory()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'type' => MessageType::Reply->value,
            'body_text' => $body,
            'body_html' => '<p>'.$body.'</p>',
        ]);
        $message->forceFill(['created_at' => now()->subMinutes(10 - $position)])->saveQuietly();
    }

    $deletedStaff->delete();
    actingAs($customer, 'customer');

    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/Tickets/Show')
            ->where('ticket.id', $ticket->id)
            ->missing('ticket.assigned_to')
            ->missing('ticket.assignee')
            ->has('ticket.messages', 4)
            ->where('ticket.messages.0.sender_kind', 'customer')
            ->where('ticket.messages.0.sender', [
                'name' => 'Portal Customer',
                'avatar' => 'https://example.com/customer.png',
            ])
            ->missing('ticket.messages.0.sender_id')
            ->missing('ticket.messages.0.sender_type')
            ->where('ticket.messages.1.sender_kind', 'support')
            ->where('ticket.messages.1.sender', [
                'name' => 'Support Agent',
                'avatar' => 'https://example.com/agent.png',
            ])
            ->missing('ticket.messages.1.sender_id')
            ->missing('ticket.messages.1.sender_type')
            ->where('ticket.messages.2.sender_kind', 'support')
            ->where('ticket.messages.2.sender', [
                'name' => 'Support Administrator',
                'avatar' => 'https://example.com/administrator.png',
            ])
            ->missing('ticket.messages.2.sender_id')
            ->missing('ticket.messages.2.sender_type')
            ->where('ticket.messages.3.sender_kind', 'support')
            ->where('ticket.messages.3.sender', null)
            ->missing('ticket.messages.3.sender_id')
            ->missing('ticket.messages.3.sender_type')
        );
});

test('customer can reply to their ticket', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    actingAs($customer, 'customer');

    post(route('customer.tickets.reply', $ticket), [
        'body' => 'This is my reply',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('messages', [
        'ticket_id' => $ticket->id,
        'sender_type' => Customer::class,
        'sender_id' => $customer->id,
        'body_text' => 'This is my reply',
    ]);
});

test('customer cannot reply to a closed ticket through a direct request', function () {
    Storage::fake('private');
    config([
        'attachments.disk' => 'private',
        'attachments.scanning_required' => false,
    ]);

    $customer = Customer::factory()->create();
    $lastActivityAt = now()->subDay()->startOfSecond();
    $ticket = Ticket::factory()->closed()->create([
        'customer_id' => $customer->id,
        'last_activity_at' => $lastActivityAt,
    ]);

    actingAs($customer, 'customer');

    post(route('customer.tickets.reply', $ticket), [
        'body' => 'This closed ticket should remain immutable',
        'attachments' => [UploadedFile::fake()->createWithContent('closed.txt', 'closed attachment')],
    ])->assertStatus(409);

    expect(Message::query()->where('ticket_id', $ticket->id)->count())->toBe(0)
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->fresh()->last_activity_at->equalTo($lastActivityAt))->toBeTrue()
        ->and(Storage::disk('private')->allFiles())->toBe([]);

    $this->assertDatabaseCount('attachments', 0);
});

test('customer cannot reply to other customers ticket', function () {
    $customer1 = Customer::factory()->create();
    $customer2 = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer2->id]);

    actingAs($customer1, 'customer');

    post(route('customer.tickets.reply', $ticket), [
        'body' => 'Unauthorized reply',
    ])
        ->assertStatus(403);

    $this->assertDatabaseMissing('messages', [
        'ticket_id' => $ticket->id,
        'sender_id' => $customer1->id,
    ]);
});

test('customer can logout', function () {
    $customer = Customer::factory()->create();

    actingAs($customer, 'customer');

    post(route('customer.logout'))
        ->assertRedirect();

    expect(auth()->guard('customer')->check())->toBeFalse();
});

test('unauthenticated customer cannot access tickets', function () {
    get(route('customer.tickets.index'))
        ->assertStatus(302)
        ->assertRedirect(route('customer.login'));
});
