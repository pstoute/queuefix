<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TicketStatus;
use App\Services\TicketStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TicketStatusController extends Controller
{
    public function __construct(
        private TicketStatusService $statusService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Statuses/Index', [
            'statuses' => TicketStatus::withTrashed()
                ->withCount('tickets')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $attributes = $this->validateStatus($request);
        $this->statusService->create($attributes);

        return back()->with('success', 'Ticket status created.');
    }

    public function update(Request $request, TicketStatus $ticketStatus): RedirectResponse
    {
        $attributes = $this->validateStatus($request, $ticketStatus);
        $this->statusService->update($ticketStatus, $attributes);

        return back()->with('success', 'Ticket status updated.');
    }

    public function destroy(TicketStatus $ticketStatus): RedirectResponse
    {
        $this->statusService->archive($ticketStatus);

        return back()->with('success', 'Ticket status archived.');
    }

    public function restore(TicketStatus $ticketStatus): RedirectResponse
    {
        $this->statusService->restore($ticketStatus);

        return back()->with('success', 'Ticket status restored.');
    }

    /** @return array<string, mixed> */
    private function validateStatus(Request $request, ?TicketStatus $status = null): array
    {
        $slug = $status?->is_system
            ? $status->slug
            : Str::slug((string) ($request->input('slug') ?: $request->input('name')));
        $request->merge(['slug' => $slug]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('ticket_statuses', 'slug')->ignore($status?->id),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:100', 'alpha_dash:ascii'],
            // PostgreSQL stores Laravel unsigned integers as signed int4.
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_default' => ['required', 'boolean'],
            'is_closed' => ['required', 'boolean'],
            'is_customer_visible' => ['required', 'boolean'],
            'pauses_sla' => ['required', 'boolean'],
        ]);
    }
}
