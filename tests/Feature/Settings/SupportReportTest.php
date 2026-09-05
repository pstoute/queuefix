<?php

use App\Enums\TicketPriority;
use App\Models\Customer;
use App\Models\Department;
use App\Models\SlaPauseInterval;
use App\Models\SlaPolicy;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\SupportReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-02-15 12:00:00 UTC');
    $this->admin = User::factory()->admin()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('every summary metric is deterministic and elapsed times exclude overlapping pauses', function () {
    $department = Department::create(['name' => 'Support']);
    $otherDepartment = Department::create(['name' => 'Excluded']);
    $agent = User::factory()->create(['name' => 'Avery Agent']);
    $customer = Customer::factory()->create();
    $closedStatus = TicketStatus::systemClosedStatus();
    $openStatus = TicketStatus::defaultStatus();
    $policy = SlaPolicy::factory()->create();

    $first = Ticket::factory()->create([
        'ticket_status_id' => $closedStatus->id,
        'customer_id' => $customer->id,
        'department_id' => $department->id,
        'assigned_to' => $agent->id,
        'priority' => TicketPriority::Normal,
        'created_at' => '2026-01-10 10:00:00',
        'updated_at' => '2026-01-10 13:00:00',
        'resolved_at' => '2026-01-10 13:00:00',
        'closed_at' => '2026-01-10 13:00:00',
    ]);
    $firstTimer = SlaTimer::factory()->create([
        'ticket_id' => $first->id,
        'sla_policy_id' => $policy->id,
        'created_at' => '2026-01-10 10:00:00',
        'first_responded_at' => '2026-01-10 11:00:00',
        'resolved_at' => '2026-01-10 13:00:00',
        'first_response_breached' => false,
        'resolution_breached' => false,
    ]);
    SlaPauseInterval::create([
        'sla_timer_id' => $firstTimer->id,
        'started_at' => '2026-01-10 10:15:00',
        'ended_at' => '2026-01-10 10:45:00',
        'duration_seconds' => 1800,
    ]);
    TicketRating::create([
        'ticket_id' => $first->id,
        'customer_id' => $customer->id,
        'rating' => 5,
        'submitted_at' => '2026-01-10 14:00:00',
    ]);

    $second = Ticket::factory()->create([
        'ticket_status_id' => $closedStatus->id,
        'customer_id' => $customer->id,
        'department_id' => $department->id,
        'assigned_to' => $agent->id,
        'priority' => TicketPriority::High,
        'created_at' => '2026-01-11 12:00:00',
        'updated_at' => '2026-01-11 17:00:00',
        'resolved_at' => '2026-01-11 17:00:00',
        'closed_at' => '2026-01-11 17:00:00',
    ]);
    $secondTimer = SlaTimer::factory()->create([
        'ticket_id' => $second->id,
        'sla_policy_id' => $policy->id,
        'created_at' => '2026-01-11 12:00:00',
        'first_responded_at' => '2026-01-11 14:00:00',
        'resolved_at' => '2026-01-11 17:00:00',
        'first_response_breached' => true,
        'resolution_breached' => false,
    ]);
    SlaPauseInterval::create([
        'sla_timer_id' => $secondTimer->id,
        'started_at' => '2026-01-11 12:30:00',
        'ended_at' => '2026-01-11 13:30:00',
        'duration_seconds' => 3600,
    ]);
    TicketRating::create([
        'ticket_id' => $second->id,
        'customer_id' => $customer->id,
        'rating' => 1,
        'submitted_at' => '2026-01-11 18:00:00',
    ]);

    Ticket::factory()->create([
        'ticket_status_id' => $openStatus->id,
        'department_id' => $department->id,
        'assigned_to' => $agent->id,
        'created_at' => '2025-12-20 10:00:00',
        'updated_at' => '2025-12-20 10:00:00',
    ]);
    Ticket::factory()->create([
        'ticket_status_id' => $openStatus->id,
        'department_id' => $otherDepartment->id,
        'created_at' => '2026-01-10 10:00:00',
        'updated_at' => '2026-01-10 10:00:00',
    ]);

    actingAs($this->admin);
    get(route('settings.reports.index', [
        'from' => '2026-01-01',
        'to' => '2026-01-31',
        'timezone' => 'UTC',
        'department_id' => $department->id,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.created_count', 2)
        ->where('report.summary.resolved_count', 2)
        ->where('report.summary.currently_open_count', 1)
        ->where('report.summary.first_response_sla_percent', fn ($value) => (float) $value === 50.0)
        ->where('report.summary.resolution_sla_percent', fn ($value) => (float) $value === 100.0)
        ->where('report.summary.first_response_median_seconds', fn ($value) => (float) $value === 2700.0)
        ->where('report.summary.first_response_average_seconds', fn ($value) => (float) $value === 2700.0)
        ->where('report.summary.resolution_median_seconds', fn ($value) => (float) $value === 11700.0)
        ->where('report.summary.resolution_average_seconds', fn ($value) => (float) $value === 11700.0)
        ->where('report.summary.rating_count', 2)
        ->where('report.summary.average_csat', fn ($value) => (float) $value === 3.0)
        ->where('report.summary.low_rating_percent', fn ($value) => (float) $value === 50.0)
        ->has('report.breakdowns.department', 1)
        ->where('report.breakdowns.department.0.label', 'Support')
        ->where('report.breakdowns.department.0.created_count', 2)
        ->where('report.breakdowns.department.0.resolved_count', 2)
        ->where('report.breakdowns.department.0.currently_open_count', 1)
        ->where('report.breakdowns.department.0.first_response_sla_percent', fn ($value) => (float) $value === 50.0)
        ->where('report.breakdowns.department.0.resolution_sla_percent', fn ($value) => (float) $value === 100.0)
        ->where('report.breakdowns.department.0.rating_count', 2)
        ->where('report.breakdowns.department.0.average_csat', fn ($value) => (float) $value === 3.0)
        ->where('report.breakdowns.department.0.low_rating_percent', fn ($value) => (float) $value === 50.0)
        ->has('report.breakdowns.priority', 2)
        ->has('report.breakdowns.status', 2)
        ->has('report.breakdowns.assignee', 1)
    );
});

test('local calendar boundaries are converted to one consistent half-open UTC range', function () {
    $openStatus = TicketStatus::defaultStatus();

    foreach ([
        '2026-01-01 07:59:59',
        '2026-01-01 08:00:00',
        '2026-01-02 07:59:59',
        '2026-01-02 08:00:00',
    ] as $createdAt) {
        Ticket::factory()->create([
            'ticket_status_id' => $openStatus->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    actingAs($this->admin);
    get(route('settings.reports.index', [
        'from' => '2026-01-01',
        'to' => '2026-01-01',
        'timezone' => 'America/Los_Angeles',
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.timezone', 'America/Los_Angeles')
        ->where('report.summary.created_count', 2)
        ->where('report.summary.currently_open_count', 3)
    );
});

test('assignee filters scope every aggregate cohort', function () {
    $selectedAgent = User::factory()->create();
    $otherAgent = User::factory()->create();

    Ticket::factory()->count(2)->create([
        'assigned_to' => $selectedAgent->id,
        'created_at' => '2026-01-10 10:00:00',
        'updated_at' => '2026-01-10 10:00:00',
    ]);
    Ticket::factory()->count(3)->create([
        'assigned_to' => $otherAgent->id,
        'created_at' => '2026-01-10 10:00:00',
        'updated_at' => '2026-01-10 10:00:00',
    ]);

    actingAs($this->admin);
    get(route('settings.reports.index', [
        'from' => '2026-01-01',
        'to' => '2026-01-31',
        'timezone' => 'UTC',
        'agent_id' => $selectedAgent->id,
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.created_count', 2)
        ->where('report.summary.currently_open_count', 2)
        ->has('report.breakdowns.assignee', 1)
        ->where('report.breakdowns.assignee.0.key', $selectedAgent->id)
    );
});

test('empty metric denominators remain null instead of reporting misleading zero rates', function () {
    actingAs($this->admin);

    get(route('settings.reports.index', [
        'from' => '2026-01-01',
        'to' => '2026-01-31',
        'timezone' => 'UTC',
    ]))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.first_response_sla_percent', null)
        ->where('report.summary.resolution_sla_percent', null)
        ->where('report.summary.first_response_median_seconds', null)
        ->where('report.summary.first_response_average_seconds', null)
        ->where('report.summary.resolution_median_seconds', null)
        ->where('report.summary.resolution_average_seconds', null)
        ->where('report.summary.rating_count', 0)
        ->where('report.summary.average_csat', null)
        ->where('report.summary.low_rating_percent', null)
    );
});

test('reports and aggregate CSV exports require administrative authorization', function () {
    $agent = User::factory()->create();
    actingAs($agent);

    get(route('settings.reports.index'))->assertForbidden();
    get(route('settings.reports.export'))->assertForbidden();
});

test('report query count stays constant as ticket volume grows', function () {
    Ticket::factory()->count(50)->create([
        'created_at' => '2026-01-10 10:00:00',
        'updated_at' => '2026-01-10 10:00:00',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(SupportReportService::class)->generate(
        CarbonImmutable::parse('2026-01-01 00:00:00 UTC'),
        CarbonImmutable::parse('2026-02-01 00:00:00 UTC'),
    );

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(10);
});

test('CSV export contains aggregate-safe rows and neutralizes spreadsheet formulas', function () {
    $department = Department::create(['name' => '=Formula']);
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->closed()->create([
        'subject' => 'Private ticket subject',
        'customer_id' => $customer->id,
        'department_id' => $department->id,
        'created_at' => '2026-01-10 10:00:00',
        'resolved_at' => '2026-01-10 12:00:00',
        'closed_at' => '2026-01-10 12:00:00',
    ]);
    TicketRating::create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'rating' => 1,
        'feedback' => 'Private rating feedback',
        'submitted_at' => '2026-01-10 13:00:00',
    ]);

    actingAs($this->admin);
    $response = get(route('settings.reports.export', [
        'from' => '2026-01-01',
        'to' => '2026-01-31',
        'timezone' => 'UTC',
    ]));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->getContent())
        ->toContain('period_from,period_to,timezone,dimension,label')
        ->toContain("department,'=Formula")
        ->not->toContain('Private ticket subject')
        ->not->toContain($customer->email)
        ->not->toContain('Private rating feedback');
});
