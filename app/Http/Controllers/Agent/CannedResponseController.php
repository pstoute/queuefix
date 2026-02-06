<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CannedResponseController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $responses = CannedResponse::with('creator')
            ->orderBy('title')
            ->get();

        if ($request->wantsJson()) {
            return response()->json($responses);
        }

        return Inertia::render('Settings/CannedResponses/Index', [
            'cannedResponses' => $responses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        CannedResponse::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Canned response created.');
    }

    public function update(Request $request, CannedResponse $cannedResponse): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $cannedResponse->update($validated);

        return back()->with('success', 'Canned response updated.');
    }

    public function destroy(CannedResponse $cannedResponse): RedirectResponse
    {
        $cannedResponse->delete();

        return back()->with('success', 'Canned response deleted.');
    }

    public function render(Request $request, CannedResponse $cannedResponse): JsonResponse
    {
        $ticket = null;
        if ($request->filled('ticket_id')) {
            $ticket = \App\Models\Ticket::with('customer')->find($request->ticket_id);
        }

        $body = $cannedResponse->body;
        $body = str_replace('{{customer_name}}', $ticket?->customer?->name ?? '', $body);
        $body = str_replace('{{ticket_number}}', $ticket?->ticket_number ?? '', $body);
        $body = str_replace('{{agent_name}}', $request->user()->name ?? '', $body);

        return response()->json(['body' => $body]);
    }
}
