<?php

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Support\Facades\URL;
use function Pest\Laravel\{actingAs, get, post};

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

    $verifyUrl = URL::temporarySignedRoute(
        'customer.auth.verify',
        now()->addMinutes(30),
        ['customer' => $customer->id]
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
