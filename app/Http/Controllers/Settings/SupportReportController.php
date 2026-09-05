<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\SupportReportCsvExporter;
use App\Services\SupportReportService;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupportReportController extends Controller
{
    public function __construct(
        private SupportReportService $reportService,
        private SupportReportCsvExporter $csvExporter,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);

        return Inertia::render('Settings/Reports/Index', [
            'report' => $this->report($filters),
            'filters' => $this->publicFilters($filters),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'agents' => User::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $filters = $this->validatedFilters($request);
        $range = $this->publicFilters($filters);
        $contents = $this->csvExporter->export($range, $this->report($filters));
        $filename = "support-report-{$range['from']}-to-{$range['to']}.csv";

        return response($contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   timezone: string,
     *   department_id: string|null,
     *   agent_id: string|null,
     *   from_utc: CarbonImmutable,
     *   to_utc_exclusive: CarbonImmutable
     * }
     */
    private function validatedFilters(Request $request): array
    {
        $defaultTimezone = (string) Setting::get('timezone', config('app.timezone', 'UTC'));
        if (! in_array($defaultTimezone, DateTimeZone::listIdentifiers(), true)) {
            $defaultTimezone = 'UTC';
        }

        $timezone = $request->input('timezone', $defaultTimezone);
        $calendarTimezone = is_string($timezone)
            && in_array($timezone, DateTimeZone::listIdentifiers(), true)
                ? $timezone
                : $defaultTimezone;
        $today = CarbonImmutable::now($calendarTimezone);

        $validated = validator([
            'from' => $request->input('from', $today->subDays(29)->toDateString()),
            'to' => $request->input('to', $today->toDateString()),
            'timezone' => $timezone,
            'department_id' => $request->input('department_id'),
            'agent_id' => $request->input('agent_id'),
        ], [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'timezone' => ['required', 'timezone'],
            'department_id' => ['nullable', 'uuid', Rule::exists('departments', 'id')],
            'agent_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
        ])->validate();

        $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], $validated['timezone'])
            ->utc();
        $toUtcExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], $validated['timezone'])
            ->addDay()
            ->utc();

        return [
            ...$validated,
            'department_id' => $validated['department_id'] ?? null,
            'agent_id' => $validated['agent_id'] ?? null,
            'from_utc' => $fromUtc,
            'to_utc_exclusive' => $toUtcExclusive,
        ];
    }

    /**
     * @param  array{from_utc: CarbonImmutable, to_utc_exclusive: CarbonImmutable, department_id: string|null, agent_id: string|null}  $filters
     * @return array{summary: array<string, int|float|null>, breakdowns: array<string, list<array<string, int|float|string|null>>>}
     */
    private function report(array $filters): array
    {
        return $this->reportService->generate(
            $filters['from_utc'],
            $filters['to_utc_exclusive'],
            $filters['department_id'],
            $filters['agent_id'],
        );
    }

    /**
     * @param  array{from: string, to: string, timezone: string, department_id: string|null, agent_id: string|null}  $filters
     * @return array{from: string, to: string, timezone: string, department_id: string|null, agent_id: string|null}
     */
    private function publicFilters(array $filters): array
    {
        return [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'timezone' => $filters['timezone'],
            'department_id' => $filters['department_id'],
            'agent_id' => $filters['agent_id'],
        ];
    }
}
