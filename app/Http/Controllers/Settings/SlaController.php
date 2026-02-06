<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\SlaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SlaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Sla/Index', [
            'slaPolicies' => SlaPolicy::orderByRaw("CASE priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
                END")->get(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'priority' => 'required|string|in:' . implode(',', array_column(TicketPriority::cases(), 'value')),
            'first_response_hours' => 'required|numeric|min:0.1',
            'resolution_hours' => 'required|numeric|min:0.1',
            'is_active' => 'boolean',
        ]);

        $validated['priority'] = TicketPriority::from($validated['priority']);

        SlaPolicy::create($validated);

        return back()->with('success', 'SLA policy created.');
    }

    public function update(Request $request, SlaPolicy $slaPolicy): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'first_response_hours' => 'required|numeric|min:0.1',
            'resolution_hours' => 'required|numeric|min:0.1',
            'is_active' => 'boolean',
        ]);

        $slaPolicy->update($validated);

        return back()->with('success', 'SLA policy updated.');
    }

    public function destroy(SlaPolicy $slaPolicy): RedirectResponse
    {
        $slaPolicy->delete();

        return back()->with('success', 'SLA policy deleted.');
    }
}
