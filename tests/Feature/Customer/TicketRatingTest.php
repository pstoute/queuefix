<?php

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\User;
use App\Notifications\TicketLowRatingNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('the owning customer can rate a closed ticket once without replacing the original', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->closed()->create(['customer_id' => $customer->id]);
    actingAs($customer, 'customer');

    post(route('customer.tickets.rating.store', $ticket), [
        'rating' => 5,
        'feedback' => '  Great support.  ',
    ])->assertRedirect()->assertSessionHas('success');

    $rating = TicketRating::query()->sole();
    expect($rating->ticket_id)->toBe($ticket->id)
        ->and($rating->customer_id)->toBe($customer->id)
        ->and($rating->rating)->toBe(5)
        ->and($rating->feedback)->toBe('Great support.')
        ->and($rating->submitted_at)->not->toBeNull();

    post(route('customer.tickets.rating.store', $ticket), [
        'rating' => 1,
        'feedback' => 'Replace it',
    ])->assertSessionHasErrors('rating');

    expect($rating->fresh()->rating)->toBe(5)
        ->and($rating->fresh()->feedback)->toBe('Great support.')
        ->and(TicketRating::query()->count())->toBe(1);
});

test('ratings enforce customer ownership current closure and score range', function () {
    $owner = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $openTicket = Ticket::factory()->create(['customer_id' => $owner->id]);
    $closedTicket = Ticket::factory()->closed()->create(['customer_id' => $owner->id]);

    actingAs($otherCustomer, 'customer');
    post(route('customer.tickets.rating.store', $closedTicket), ['rating' => 4])
        ->assertForbidden();

    actingAs($owner, 'customer');
    post(route('customer.tickets.rating.store', $openTicket), ['rating' => 4])
        ->assertSessionHasErrors('rating');
    post(route('customer.tickets.rating.store', $closedTicket), ['rating' => 0])
        ->assertSessionHasErrors('rating');
    post(route('customer.tickets.rating.store', $closedTicket), ['rating' => 6])
        ->assertSessionHasErrors('rating');

    expect(TicketRating::query()->count())->toBe(0);
});

test('low ratings notify the active assignee and support managers exactly once', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $assigneeAndManager = User::factory()->supportManager()->create();
    $manager = User::factory()->supportManager()->create();
    $inactiveManager = User::factory()->supportManager()->create(['is_active' => false]);
    $ticket = Ticket::factory()->closed()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $assigneeAndManager->id,
    ]);
    actingAs($customer, 'customer');

    post(route('customer.tickets.rating.store', $ticket), [
        'rating' => 2,
        'feedback' => '<script>alert("feedback")</script>',
    ])->assertRedirect();

    Notification::assertSentToTimes($assigneeAndManager, TicketLowRatingNotification::class, 1);
    Notification::assertSentToTimes($manager, TicketLowRatingNotification::class, 1);
    Notification::assertNotSentTo($inactiveManager, TicketLowRatingNotification::class);
    Notification::assertSentTo(
        $manager,
        TicketLowRatingNotification::class,
        function (TicketLowRatingNotification $notification) use ($ticket, $manager): bool {
            $data = $notification->toDatabase($manager);

            return $notification->url() === route('agent.tickets.show', $ticket)
                && $data['event'] === 'low_ticket_rating'
                && $data['rating'] === 2
                && ! array_key_exists('feedback', $data);
        },
    );

    $rating = TicketRating::query()->sole();
    expect($rating->staff_notified_at)->not->toBeNull()
        ->and($rating->feedback)->toBe('<script>alert("feedback")</script>');
});

test('non-low ratings do not notify staff', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $manager = User::factory()->supportManager()->create();
    $ticket = Ticket::factory()->closed()->create(['customer_id' => $customer->id]);
    actingAs($customer, 'customer');

    post(route('customer.tickets.rating.store', $ticket), ['rating' => 3])
        ->assertRedirect();

    Notification::assertNothingSent();
    expect(TicketRating::query()->sole()->staff_notified_at)->toBeNull();
});

test('rating details are exposed only on the authorized customer and staff ticket views', function () {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->closed()->create(['customer_id' => $customer->id]);
    TicketRating::factory()->create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'rating' => 1,
        'feedback' => '<img src=x onerror=alert(1)>',
    ]);

    actingAs($customer, 'customer');
    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.rating.rating', 1)
            ->where('ticket.rating.feedback', '<img src=x onerror=alert(1)>')
        );

    actingAs($otherCustomer, 'customer');
    get(route('customer.tickets.show', $ticket))->assertForbidden();

    actingAs($agent);
    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ticket.rating.rating', 1)
            ->where('ticket.rating.feedback', '<img src=x onerror=alert(1)>')
            ->where('ticket.rating.customer.id', $customer->id)
        );
});
