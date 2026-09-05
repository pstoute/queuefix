<?php

use App\Models\Setting;
use App\Models\SlaPauseInterval;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Services\SlaService;
use App\Services\TicketService;
use App\Services\TicketStatusService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-04 09:00:00');
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->slaService = app(SlaService::class);
    $this->openStatus = TicketStatus::defaultStatus();
    $this->pendingStatus = $this->ticketStatusAt(20);
    $this->onHoldStatus = $this->ticketStatusAt(30);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('open to pausing to open records an exact durable interval and shifts incomplete deadlines', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $firstDue = now()->addHours(4);
    $resolutionDue = now()->addHours(24);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => $firstDue,
        'resolution_due_at' => $resolutionDue,
    ]);

    $this->slaService->handleStatusChange($ticket, $this->openStatus, $this->pendingStatus);
    Carbon::setTestNow(now()->addSeconds(137));
    $this->slaService->handleStatusChange($ticket, $this->pendingStatus, $this->openStatus);

    $timer->refresh();
    $interval = $timer->pauseIntervals()->sole();
    expect($timer->paused_at)->toBeNull()
        ->and($timer->total_paused_seconds)->toBe(137)
        ->and($timer->first_response_due_at->equalTo($firstDue->copy()->addSeconds(137)))->toBeTrue()
        ->and($timer->resolution_due_at->equalTo($resolutionDue->copy()->addSeconds(137)))->toBeTrue()
        ->and($interval->started_at->equalTo(Carbon::parse('2026-09-04 09:00:00')))->toBeTrue()
        ->and($interval->ended_at?->equalTo(now()))->toBeTrue()
        ->and($interval->duration_seconds)->toBe(137);
});

test('moving between pausing statuses does not restart or double count the pause', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->pendingStatus->id]);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => now(),
    ]);
    SlaPauseInterval::create([
        'sla_timer_id' => $timer->id,
        'started_at' => now(),
    ]);

    Carbon::setTestNow(now()->addMinutes(15));
    $this->slaService->handleStatusChange($ticket, $this->pendingStatus, $this->onHoldStatus);

    $timer->refresh();
    expect($timer->paused_at?->equalTo(Carbon::parse('2026-09-04 09:00:00')))->toBeTrue()
        ->and($timer->total_paused_seconds)->toBe(0)
        ->and($timer->pauseIntervals()->count())->toBe(1);
});

test('a duplicate status update leaves SLA deadlines and ledger unchanged', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $timer = SlaTimer::factory()->create(['ticket_id' => $ticket->id]);
    $firstDue = $timer->first_response_due_at->copy();
    $resolutionDue = $timer->resolution_due_at->copy();

    Carbon::setTestNow(now()->addMinutes(15));
    app(TicketService::class)->updateStatus($ticket, $this->openStatus);

    $timer->refresh();
    expect($timer->paused_at)->toBeNull()
        ->and($timer->total_paused_seconds)->toBe(0)
        ->and($timer->first_response_due_at->equalTo($firstDue))->toBeTrue()
        ->and($timer->resolution_due_at->equalTo($resolutionDue))->toBeTrue()
        ->and($timer->pauseIntervals()->count())->toBe(0);
});

test('resuming never shifts a completed first-response deadline', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $firstDue = now()->addHours(4);
    $resolutionDue = now()->addHours(24);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => $firstDue,
        'first_responded_at' => now(),
        'resolution_due_at' => $resolutionDue,
    ]);

    $this->slaService->handleStatusChange($ticket, $this->openStatus, $this->pendingStatus);
    Carbon::setTestNow(now()->addMinutes(10));
    $this->slaService->handleStatusChange($ticket, $this->pendingStatus, $this->openStatus);

    $timer->refresh();
    expect($timer->first_response_due_at->equalTo($firstDue))->toBeTrue()
        ->and($timer->resolution_due_at->equalTo($resolutionDue->copy()->addMinutes(10)))->toBeTrue();
});

test('resuming never shifts a completed resolution deadline', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $firstDue = now()->addHours(4);
    $resolutionDue = now()->addHours(24);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => $firstDue,
        'resolution_due_at' => $resolutionDue,
        'resolved_at' => now(),
    ]);

    $this->slaService->handleStatusChange($ticket, $this->openStatus, $this->pendingStatus);
    Carbon::setTestNow(now()->addMinutes(10));
    $this->slaService->handleStatusChange($ticket, $this->pendingStatus, $this->openStatus);

    $timer->refresh();
    expect($timer->first_response_due_at->equalTo($firstDue->copy()->addMinutes(10)))->toBeTrue()
        ->and($timer->resolution_due_at->equalTo($resolutionDue))->toBeTrue();
});

test('entering a pause records clocks that were already overdue as breached', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => now()->subSecond(),
        'resolution_due_at' => now()->subSecond(),
        'first_response_breached' => false,
        'resolution_breached' => false,
    ]);

    $this->slaService->handleStatusChange($ticket, $this->openStatus, $this->pendingStatus);

    $timer->refresh();
    expect($timer->paused_at?->equalTo(now()))->toBeTrue()
        ->and($timer->first_response_breached)->toBeTrue()
        ->and($timer->resolution_breached)->toBeTrue()
        ->and($timer->pauseIntervals()->count())->toBe(1);
});

test('the scheduled breach check ignores every actively paused incomplete clock', function () {
    $timer = SlaTimer::factory()->create([
        'paused_at' => now()->subHour(),
        'first_response_due_at' => now()->subMinutes(30),
        'resolution_due_at' => now()->subMinutes(30),
        'first_response_breached' => false,
        'resolution_breached' => false,
    ]);

    $this->slaService->checkBreaches();

    $timer->refresh();
    expect($timer->first_response_breached)->toBeFalse()
        ->and($timer->resolution_breached)->toBeFalse();
});

test('a first response received during a pause is measured at the frozen instant', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->pendingStatus->id]);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => now(),
        'first_response_due_at' => now()->addMinute(),
    ]);

    Carbon::setTestNow(now()->addHours(2));
    $this->slaService->recordFirstResponse($ticket);

    $timer->refresh();
    expect($timer->first_responded_at?->equalTo(now()))->toBeTrue()
        ->and($timer->first_response_breached)->toBeFalse();
});

test('a timer starts paused when the ticket default status pauses SLA', function () {
    $this->openStatus->update(['pauses_sla' => true]);
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    SlaPolicy::factory()->create(['priority' => $ticket->priority]);

    $timer = $this->slaService->initializeTimer($ticket);

    expect($timer?->paused_at?->equalTo(now()))->toBeTrue()
        ->and($timer?->pauseIntervals()->count())->toBe(1);
});

test('changing pause configuration reconciles timers already in that status', function () {
    $ticket = Ticket::factory()->create(['ticket_status_id' => $this->openStatus->id]);
    $timer = SlaTimer::factory()->create(['ticket_id' => $ticket->id]);
    $service = app(TicketStatusService::class);

    $service->update($this->openStatus, [
        'is_default' => true,
        'pauses_sla' => true,
    ]);
    expect($timer->fresh()->paused_at?->equalTo(now()))->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(5));
    $service->update($this->openStatus->fresh(), [
        'is_default' => true,
        'pauses_sla' => false,
    ]);

    $timer->refresh();
    expect($timer->paused_at)->toBeNull()
        ->and($timer->total_paused_seconds)->toBe(300)
        ->and($timer->pauseIntervals()->sole()->duration_seconds)->toBe(300);
});

test('SLA summaries classify on-track approaching paused and breached clocks', function () {
    $timer = SlaTimer::factory()->create([
        'created_at' => now()->subMinutes(90),
        'first_response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addHours(4),
    ]);

    $summary = $this->slaService->getSlaStatus($timer);
    expect($summary['first_response']['status'])->toBe('approaching')
        ->and($summary['resolution']['status'])->toBe('on_track');

    $timer->update(['paused_at' => now()]);
    $summary = $this->slaService->getSlaStatus($timer->fresh());
    expect($summary['first_response']['status'])->toBe('paused')
        ->and($summary['resolution']['status'])->toBe('paused');

    $timer->update([
        'paused_at' => null,
        'first_response_due_at' => now()->subSecond(),
    ]);
    $summary = $this->slaService->getSlaStatus($timer->fresh());
    expect($summary['first_response']['status'])->toBe('breached');
});
