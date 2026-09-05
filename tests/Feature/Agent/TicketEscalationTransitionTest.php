<?php

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;

afterEach(fn () => Carbon::setTestNow());

test('status and priority endpoints persist deterministic trigger timestamps only on transitions', function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $pending = $this->ticketStatusAt(20);
    actingAs($agent);

    patch(route('agent.tickets.priority', $ticket), [
        'priority' => TicketPriority::High->value,
    ])->assertRedirect();
    patch(route('agent.tickets.status', $ticket), [
        'status' => $pending->slug,
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->priority_changed_at?->toDateTimeString())->toBe('2026-09-04 12:00:00')
        ->and($ticket->status_changed_at?->toDateTimeString())->toBe('2026-09-04 12:00:00');

    Carbon::setTestNow('2026-09-04 12:05:00');
    patch(route('agent.tickets.priority', $ticket), [
        'priority' => TicketPriority::High->value,
    ])->assertRedirect();
    patch(route('agent.tickets.status', $ticket), [
        'status' => $pending->slug,
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->priority_changed_at?->toDateTimeString())->toBe('2026-09-04 12:00:00')
        ->and($ticket->status_changed_at?->toDateTimeString())->toBe('2026-09-04 12:00:00');

});
