<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Services\SlaService;
use Carbon\Carbon;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
    $this->slaService = new SlaService;
});

afterEach(function () {
    Carbon::setTestNow();
});

test('initializing SLA timer with matching policy', function () {
    $policy = SlaPolicy::factory()->create([
        'priority' => TicketPriority::Normal,
        'first_response_hours' => 4,
        'resolution_hours' => 24,
        'is_active' => true,
    ]);

    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Normal]);

    $timer = $this->slaService->initializeTimer($ticket);

    expect($timer)->not->toBeNull();
    expect($timer->ticket_id)->toBe($ticket->id);
    expect($timer->sla_policy_id)->toBe($policy->id);
    expect($timer->first_response_started_at)->not->toBeNull();
    expect($timer->first_response_budget_seconds)->toBe(4 * 3600);
    expect($timer->first_response_due_at)->not->toBeNull();
    expect($timer->resolution_started_at)->not->toBeNull();
    expect($timer->resolution_budget_seconds)->toBe(24 * 3600);
    expect($timer->resolution_due_at)->not->toBeNull();
});

test('no timer when no matching policy', function () {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Urgent]);

    $timer = $this->slaService->initializeTimer($ticket);

    expect($timer)->toBeNull();
});

test('no timer when policy is inactive', function () {
    $policy = SlaPolicy::factory()->inactive()->create([
        'priority' => TicketPriority::Normal,
    ]);

    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Normal]);

    $timer = $this->slaService->initializeTimer($ticket);

    expect($timer)->toBeNull();
});

test('recording first response', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => now()->addHours(4),
        'first_responded_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordFirstResponse($ticket);

    $timer->refresh();
    expect($timer->first_responded_at)->not->toBeNull();
    expect($timer->first_response_breached)->toBeFalse();
});

test('recording first response marks as breached when overdue', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_response_due_at' => now()->subHours(1),
        'first_responded_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordFirstResponse($ticket);

    $timer->refresh();
    expect($timer->first_responded_at)->not->toBeNull();
    expect($timer->first_response_breached)->toBeTrue();
});

test('recording first response does nothing when already responded', function () {
    $ticket = Ticket::factory()->create();
    $firstResponseTime = now()->subHour();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'first_responded_at' => $firstResponseTime,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordFirstResponse($ticket);

    $timer->refresh();
    expect($timer->first_responded_at->timestamp)->toBe($firstResponseTime->timestamp);
});

test('recording resolution', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_due_at' => now()->addHours(24),
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordResolution($ticket);

    $timer->refresh();
    expect($timer->resolved_at)->not->toBeNull();
    expect($timer->resolution_breached)->toBeFalse();
});

test('recording resolution marks as breached when overdue', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_due_at' => now()->subHours(2),
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordResolution($ticket);

    $timer->refresh();
    expect($timer->resolved_at)->not->toBeNull();
    expect($timer->resolution_breached)->toBeTrue();
});

test('recording resolution with paused timer excludes paused time', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_due_at' => now()->addHours(24),
        'paused_at' => now()->subHours(2),
        'total_paused_seconds' => 7200, // 2 hours
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->recordResolution($ticket);

    $timer->refresh();
    expect($timer->resolved_at)->not->toBeNull();
});

test('SLA pause on pending status', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->handleStatusChange($ticket, TicketStatus::Open, TicketStatus::Pending);

    $timer->refresh();
    expect($timer->paused_at)->not->toBeNull();
});

test('SLA pause on hold status', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->handleStatusChange($ticket, TicketStatus::Open, TicketStatus::OnHold);

    $timer->refresh();
    expect($timer->paused_at)->not->toBeNull();
});

test('SLA resume from pending to open', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $pausedTime = now()->subHours(2);
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => $pausedTime,
        'total_paused_seconds' => 0,
        'first_response_due_at' => now()->addHours(4),
        'resolution_due_at' => now()->addHours(24),
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->handleStatusChange($ticket, TicketStatus::Pending, TicketStatus::Open);

    $timer->refresh();
    expect($timer->paused_at)->toBeNull();
    expect($timer->total_paused_seconds)->toBeInt()->toBeGreaterThan(0);
});

test('SLA resume extends due dates by paused time', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $pausedTime = now()->subHours(2);
    $originalFirstResponseDue = now()->addHours(4);
    $originalResolutionDue = now()->addHours(24);

    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => $pausedTime,
        'total_paused_seconds' => 0,
        'first_response_due_at' => $originalFirstResponseDue,
        'first_responded_at' => null,
        'resolution_due_at' => $originalResolutionDue,
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->handleStatusChange($ticket, TicketStatus::Pending, TicketStatus::Open);

    $timer->refresh();
    expect($timer->first_response_due_at->timestamp)->toBeGreaterThan($originalFirstResponseDue->timestamp);
    expect($timer->resolution_due_at->timestamp)->toBeGreaterThan($originalResolutionDue->timestamp);
});

test('SLA resume does not extend due dates if already met', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $pausedTime = now()->subHours(2);
    $originalFirstResponseDue = now()->addHours(4);
    $originalResolutionDue = now()->addHours(24);

    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'paused_at' => $pausedTime,
        'total_paused_seconds' => 0,
        'first_response_due_at' => $originalFirstResponseDue,
        'first_responded_at' => now()->subDays(1), // Already responded
        'resolution_due_at' => $originalResolutionDue,
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    $this->slaService->handleStatusChange($ticket, TicketStatus::Pending, TicketStatus::Open);

    $timer->refresh();
    // First response due should not change since already responded
    expect($timer->first_response_due_at->timestamp)->toBe($originalFirstResponseDue->timestamp);
    // Resolution due should be extended
    expect($timer->resolution_due_at->timestamp)->toBeGreaterThan($originalResolutionDue->timestamp);
});

test('SLA breach detection for first response', function () {
    SlaTimer::factory()->create([
        'first_responded_at' => null,
        'first_response_breached' => false,
        'first_response_due_at' => now()->subHours(1),
        'paused_at' => null,
    ]);

    SlaTimer::factory()->create([
        'first_responded_at' => null,
        'first_response_breached' => false,
        'first_response_due_at' => now()->addHours(1),
        'paused_at' => null,
    ]);

    $this->slaService->checkBreaches();

    $breached = SlaTimer::where('first_response_breached', true)->count();
    expect($breached)->toBe(1);
});

test('SLA breach detection for resolution', function () {
    SlaTimer::factory()->create([
        'resolved_at' => null,
        'resolution_breached' => false,
        'resolution_due_at' => now()->subHours(1),
        'paused_at' => null,
    ]);

    SlaTimer::factory()->create([
        'resolved_at' => null,
        'resolution_breached' => false,
        'resolution_due_at' => now()->addHours(1),
        'paused_at' => null,
    ]);

    $this->slaService->checkBreaches();

    $breached = SlaTimer::where('resolution_breached', true)->count();
    expect($breached)->toBe(1);
});

test('paused timers are not marked as breached', function () {
    SlaTimer::factory()->create([
        'first_responded_at' => null,
        'first_response_breached' => false,
        'first_response_due_at' => now()->subHours(1),
        'paused_at' => now()->subMinutes(30),
    ]);

    $this->slaService->checkBreaches();

    $breached = SlaTimer::where('first_response_breached', true)->count();
    expect($breached)->toBe(0);
});

test('paused time is excluded from SLA calculation', function () {
    $ticket = Ticket::factory()->create();
    $timer = SlaTimer::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_due_at' => now()->addHours(1),
        'paused_at' => now(),
        'total_paused_seconds' => 7200, // 2 hours paused
        'resolved_at' => null,
    ]);

    $ticket->setRelation('slaTimer', $timer);

    // Even though resolution_due_at is 1 hour from now,
    // 2 hours of paused time means we effectively have 3 hours
    $status = $this->slaService->getSlaStatus($timer);

    expect($status['resolution']['state'])->toBe('paused');
});

test('four hour clock is on track at fifty percent remaining', function () {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    Carbon::setTestNow($start->copy()->addHours(2));
    $timer = fourHourTimer($start);

    $status = $this->slaService->getSlaStatus($timer)['first_response'];

    expect($status['state'])->toBe('on_track')
        ->and($status['remaining_seconds'])->toBe(7200)
        ->and($status['original_budget_seconds'])->toBe(14400)
        ->and($status['percent_remaining'])->toBe(50.0)
        ->and($status['warning_percent'])->toBe(25.0);
});

test('warning threshold uses exact frozen-time boundaries', function (int $percent, string $expectedState) {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    $remainingSeconds = (int) (14400 * ($percent / 100));
    Carbon::setTestNow($start->copy()->addSeconds(14400 - $remainingSeconds));
    $timer = fourHourTimer($start);

    $status = $this->slaService->getSlaStatus($timer)['first_response'];

    expect($status['state'])->toBe($expectedState)
        ->and($status['remaining_seconds'])->toBe($remainingSeconds)
        ->and($status['percent_remaining'])->toBe((float) $percent);
})->with([
    '26 percent' => [26, 'on_track'],
    '25 percent' => [25, 'approaching'],
    '24 percent' => [24, 'approaching'],
]);

test('approaching regression uses original budget instead of the remaining interval twice', function () {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    Carbon::setTestNow($start->copy()->addSeconds(10800));
    $timer = fourHourTimer($start);

    $status = $this->slaService->getSlaStatus($timer)['first_response'];
    $legacyRemainingSeconds = now()->diffInSeconds($timer->first_response_due_at, false);
    $legacyTotalSeconds = $timer->first_response_due_at->diffInSeconds(now());
    $legacyPercentMagnitude = (abs($legacyRemainingSeconds) / abs($legacyTotalSeconds)) * 100;

    expect($legacyPercentMagnitude)->toBe(100.0)
        ->and($status['percent_remaining'])->toBe(25.0)
        ->and($status['state'])->toBe('approaching');
});

test('zero and overdue clocks are breached', function (int $secondsAfterDue) {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    Carbon::setTestNow($start->copy()->addHours(4)->addSeconds($secondsAfterDue));
    $timer = fourHourTimer($start);

    $status = $this->slaService->getSlaStatus($timer)['first_response'];

    expect($status['state'])->toBe('breached')
        ->and($status['remaining_seconds'])->toBe(0)
        ->and($status['percent_remaining'])->toBe(0.0);
})->with([
    'exactly due' => [0],
    'one second overdue' => [1],
]);

test('configured warning threshold is applied by the service', function () {
    config(['sla.warning_percent' => 30]);
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    Carbon::setTestNow($start->copy()->addSeconds((int) (14400 * 0.71)));
    $timer = fourHourTimer($start);

    $status = $this->slaService->getSlaStatus($timer)['first_response'];

    expect($status['state'])->toBe('approaching')
        ->and($status['percent_remaining'])->toBe(29.0)
        ->and($status['warning_percent'])->toBe(30.0);
});

test('pause and resume preserve the original budget and remaining percentage', function () {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    $timer = fourHourTimer($start, $ticket);
    $ticket->setRelation('slaTimer', $timer);

    Carbon::setTestNow($start->copy()->addHour());
    $this->slaService->handleStatusChange($ticket, TicketStatus::Open, TicketStatus::Pending);

    Carbon::setTestNow($start->copy()->addHours(9));
    $this->slaService->handleStatusChange($ticket, TicketStatus::Pending, TicketStatus::Open);

    $timer->refresh();
    $status = $this->slaService->getSlaStatus($timer)['first_response'];

    expect($timer->first_response_budget_seconds)->toBe(14400)
        ->and($status['remaining_seconds'])->toBe(10800)
        ->and($status['percent_remaining'])->toBe(75.0)
        ->and($status['state'])->toBe('on_track');
});

test('paused clock freezes the remaining seconds at the pause instant', function () {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    Carbon::setTestNow($start->copy()->addHours(3));
    $timer = fourHourTimer($start);
    $timer->update(['paused_at' => $start->copy()->addHours(2)]);

    $status = $this->slaService->getSlaStatus($timer->fresh())['first_response'];

    expect($status['state'])->toBe('paused')
        ->and($status['remaining_seconds'])->toBe(7200)
        ->and($status['percent_remaining'])->toBe(50.0);
});

test('completed clock distinguishes met and breached timestamps', function (int $completionHour, string $expectedState) {
    $start = Carbon::parse('2026-09-04 08:00:00 UTC');
    $timer = fourHourTimer($start);
    $timer->update(['first_responded_at' => $start->copy()->addHours($completionHour)]);

    $status = $this->slaService->getSlaStatus($timer->fresh())['first_response'];

    expect($status['state'])->toBe($expectedState);
})->with([
    'completed before deadline' => [3, 'met'],
    'completed after deadline' => [5, 'breached'],
]);

test('missing policy has explicit empty clock payloads', function () {
    $status = $this->slaService->getSlaStatus(null);

    expect($status['first_response']['state'])->toBe('none')
        ->and($status['resolution']['state'])->toBe('none')
        ->and($status['first_response']['due_at'])->toBeNull()
        ->and($status['first_response']['remaining_seconds'])->toBeNull();
});

function fourHourTimer(Carbon $start, ?Ticket $ticket = null): SlaTimer
{
    $attributes = [
        'first_response_started_at' => $start,
        'first_response_budget_seconds' => 14400,
        'first_response_due_at' => $start->copy()->addHours(4),
        'first_responded_at' => null,
        'first_response_breached' => false,
        'paused_at' => null,
        'total_paused_seconds' => 0,
    ];

    if ($ticket) {
        $attributes['ticket_id'] = $ticket->id;
    }

    return SlaTimer::factory()->create($attributes);
}
