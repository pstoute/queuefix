<?php

use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Services\TicketService;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('closed status transitions set lifecycle timestamps and reopening clears only closure', function () {
    $service = app(TicketService::class);
    $ticket = Ticket::factory()->create();
    $closed = TicketStatus::factory()->closed()->create();
    $reopened = TicketStatus::factory()->create();

    Carbon::setTestNow('2026-09-04 10:00:00 UTC');
    $service->updateStatus($ticket, $closed);
    $ticket->refresh();

    expect($ticket->resolved_at?->toISOString())->toBe(now()->toISOString())
        ->and($ticket->closed_at?->toISOString())->toBe(now()->toISOString());

    $resolvedAt = $ticket->resolved_at;
    Carbon::setTestNow('2026-09-04 11:00:00 UTC');
    $service->updateStatus($ticket, $reopened);
    $ticket->refresh();

    expect($ticket->closed_at)->toBeNull()
        ->and($ticket->resolved_at?->toISOString())->toBe($resolvedAt?->toISOString());
});

test('ticket creation uses the administrator-selected default status', function () {
    $newDefault = app(\App\Services\TicketStatusService::class)->create([
        'name' => 'Intake',
        'slug' => 'intake',
        'color' => '#0ea5e9',
        'icon' => null,
        'sort_order' => 1,
        'is_default' => true,
        'is_closed' => false,
        'is_customer_visible' => true,
    ]);

    $ticket = Ticket::factory()->create();

    expect($ticket->ticket_status_id)->toBe($newDefault->id)
        ->and($ticket->status->is($newDefault))->toBeTrue();
});
