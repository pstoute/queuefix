<?php

use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->publicStatus = TicketStatus::factory()->create([
        'name' => 'Customer follow-up',
        'slug' => 'customer-follow-up',
        'is_customer_visible' => true,
    ]);
    $this->internalStatus = TicketStatus::factory()->internal()->create([
        'name' => 'Risk review',
        'slug' => 'risk-review',
    ]);
    actingAs($this->customer, 'customer');
});

test('customer status filters use public slugs and cannot enumerate internal statuses', function () {
    $publicTicket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'ticket_status_id' => $this->publicStatus->id,
    ]);
    Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'ticket_status_id' => $this->internalStatus->id,
    ]);

    get(route('customer.tickets.index', ['status' => $this->publicStatus->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', $this->publicStatus->slug)
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $publicTicket->id)
            ->where('statuses', fn ($statuses) => collect($statuses)
                ->pluck('slug')
                ->doesntContain($this->internalStatus->slug))
        );

    get(route('customer.tickets.index', ['status' => $this->internalStatus->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
});

test('customer payloads replace internal status metadata with a safe label', function () {
    $ticket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'ticket_status_id' => $this->internalStatus->id,
    ]);

    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.customer_status.name', 'In Progress')
            ->where('ticket.customer_status.is_closed', false)
            ->missing('ticket.customer_status.slug')
            ->missing('ticket.status')
            ->missing('ticket.ticket_status_id')
        );
});

test('customers cannot reply to tickets in any closed status', function () {
    $closedStatus = TicketStatus::factory()->closed()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'ticket_status_id' => $closedStatus->id,
    ]);

    post(route('customer.tickets.reply', $ticket), ['body' => 'Please reopen this'])
        ->assertUnprocessable();

    expect(Message::where('ticket_id', $ticket->id)->count())->toBe(0);
});
