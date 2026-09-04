<?php

use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\SlaService;
use App\Services\TicketActivityService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Setting::set('ticket_prefix', 'QF', 'general');
    Setting::set('ticket_counter', '0', 'system');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('ticket creation records one user-attributed activity with the request correlation id', function () {
    $user = User::factory()->create();
    actingAs($user);

    $this->withHeader('X-Request-ID', 'request-create-123')->post(route('agent.tickets.store'), [
        'subject' => 'Cannot sign in',
        'body' => 'Please help',
        'priority' => TicketPriority::High->value,
        'customer_email' => 'customer@example.com',
        'customer_name' => 'Example Customer',
    ])->assertRedirect();

    $ticket = Ticket::where('subject', 'Cannot sign in')->firstOrFail();
    $activity = $ticket->activities()->sole();

    expect($activity->event_type)->toBe(TicketActivityType::TicketCreated)
        ->and($activity->actor_type)->toBe(TicketActivityActorType::User)
        ->and($activity->actor_id)->toBe($user->id)
        ->and($activity->correlation_id)->toBe('request-create-123')
        ->and($activity->customer_visible)->toBeTrue()
        ->and($activity->after)->toMatchArray([
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::High->value,
        ]);
});

test('ticket domain mutations emit exactly one activity per actual change', function () {
    $service = app(TicketService::class);
    $ticket = Ticket::factory()->create();
    $agent = User::factory()->create();
    $department = Department::create([
        'name' => 'Support',
        'description' => 'Support department',
        'is_catch_all' => false,
    ]);
    $tag = Tag::factory()->create();

    $service->updateStatus($ticket, TicketStatus::Pending);
    $service->updateStatus($ticket, TicketStatus::Pending);
    $service->updatePriority($ticket, TicketPriority::Urgent);
    $service->updatePriority($ticket, TicketPriority::Urgent);
    $service->assignTicket($ticket, $agent);
    $service->assignTicket($ticket, $agent);
    $service->updateDepartment($ticket, $department);
    $service->updateDepartment($ticket, $department);
    $service->attachTag($ticket, $tag);
    $service->attachTag($ticket, $tag);
    $service->detachTag($ticket, $tag);
    $service->detachTag($ticket, $tag);

    expect($ticket->activities()->where('event_type', TicketActivityType::StatusChanged)->count())->toBe(1)
        ->and($ticket->activities()->where('event_type', TicketActivityType::PriorityChanged)->count())->toBe(1)
        ->and($ticket->activities()->where('event_type', TicketActivityType::AssignmentChanged)->count())->toBe(1)
        ->and($ticket->activities()->where('event_type', TicketActivityType::DepartmentChanged)->count())->toBe(1)
        ->and($ticket->activities()->where('event_type', TicketActivityType::TagsChanged)->count())->toBe(2)
        ->and($ticket->activities()->count())->toBe(6);
});

test('activity write failure rolls back the ticket state change', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Open]);
    $activityService = Mockery::mock(TicketActivityService::class);
    $activityService->shouldReceive('recordStatusChanged')->once()->andThrow(new RuntimeException('ledger unavailable'));
    $service = new TicketService(app(SlaService::class), $activityService);

    expect(fn () => $service->updateStatus($ticket, TicketStatus::Pending))
        ->toThrow(RuntimeException::class, 'ledger unavailable');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and(TicketActivity::where('ticket_id', $ticket->id)->count())->toBe(0);
});

test('activities are append-only and corrections are new events', function () {
    $ticket = Ticket::factory()->create();
    $service = app(TicketActivityService::class);
    $activity = $service->record(
        $ticket,
        TicketActivityType::EscalationTriggered,
        'Escalation triggered',
        correlationId: 'correction-test',
    );

    expect(fn () => $activity->update(['summary' => 'Changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $activity->fresh()->delete())
        ->toThrow(LogicException::class);

    $service->record(
        $ticket,
        TicketActivityType::EscalationCleared,
        'Correction: escalation was cleared',
        correlationId: 'correction-test',
    );

    expect($ticket->activities()->count())->toBe(2);
});

test('all supported activity event types can be recorded with system attribution', function () {
    $ticket = Ticket::factory()->create();
    $service = app(TicketActivityService::class);

    foreach (TicketActivityType::cases() as $eventType) {
        $service->record(
            $ticket,
            $eventType,
            "Recorded {$eventType->value}",
            actorType: TicketActivityActorType::System,
            correlationId: 'event-'.$eventType->value,
        );
    }

    $activities = $ticket->activities()->get();

    expect($activities)->toHaveCount(count(TicketActivityType::cases()))
        ->and($activities->every(
            fn (TicketActivity $activity): bool => $activity->actor_id === null
                && $activity->actor_type === TicketActivityActorType::System
        ))->toBeTrue();
});

test('merge and soft deletion preserve ticket activities', function () {
    $primary = Ticket::factory()->create();
    $secondary = Ticket::factory()->create();
    $activityService = app(TicketActivityService::class);
    $activityService->record(
        $secondary,
        TicketActivityType::StatusChanged,
        'Existing secondary history',
        correlationId: 'secondary-history',
    );

    app(TicketService::class)->mergeTickets($primary, $secondary);

    expect($primary->activities()->where('event_type', TicketActivityType::TicketMerged)->count())->toBe(1)
        ->and($secondary->activities()->count())->toBe(1)
        ->and($secondary->fresh()->status)->toBe(TicketStatus::Closed);

    $primary->delete();

    expect(Ticket::find($primary->id))->toBeNull()
        ->and(Ticket::withTrashed()->find($primary->id))->not->toBeNull()
        ->and(TicketActivity::where('ticket_id', $primary->id)->count())->toBe(1);
});

test('customer timeline exposes only customer-safe summaries without internal metadata', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);
    $service = app(TicketActivityService::class);
    $service->record(
        $ticket,
        TicketActivityType::StatusChanged,
        'Ticket status changed to Pending',
        before: ['status' => 'open', 'internal_reason' => 'fraud-review'],
        after: ['status' => 'pending'],
        customerVisible: true,
        correlationId: 'private-correlation-id',
    );
    $service->record(
        $ticket,
        TicketActivityType::AssignmentChanged,
        'Assigned to Internal Agent',
        after: ['assigned_to' => User::factory()->create()->id],
        correlationId: 'internal-only',
    );

    actingAs($customer, 'customer');

    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('ticket.activities', 1)
            ->where('ticket.activities.0.event_type', TicketActivityType::StatusChanged->value)
            ->where('ticket.activities.0.summary', 'Ticket status changed to Pending')
            ->missing('ticket.activities.0.before')
            ->missing('ticket.activities.0.after')
            ->missing('ticket.activities.0.actor_id')
            ->missing('ticket.activities.0.correlation_id')
            ->missing('ticket.activities.0.ticket_id')
        );
});

test('activity timeline is chronological and eager loads actors without N plus one queries', function () {
    $ticket = Ticket::factory()->create();
    $actor = User::factory()->create();
    $service = app(TicketActivityService::class);

    foreach ([3 => 'Third', 1 => 'First', 2 => 'Second'] as $hour => $summary) {
        Carbon::setTestNow(Carbon::parse("2026-09-04 0{$hour}:00:00 UTC"));
        $service->record(
            $ticket,
            TicketActivityType::PriorityChanged,
            $summary,
            actor: $actor,
            correlationId: "timeline-{$hour}",
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $loaded = Ticket::with('activities.actor')->findOrFail($ticket->id);
    $queries = collect(DB::getQueryLog());

    expect($loaded->activities->pluck('summary')->all())->toBe(['First', 'Second', 'Third'])
        ->and($loaded->activities->every(
            fn (TicketActivity $activity): bool => $activity->relationLoaded('actor')
        ))->toBeTrue()
        ->and($queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'ticket_activities')
        ))->toHaveCount(1);
});
