<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketCcRecipient;
use App\Models\User;
use App\Services\TicketCcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketCcRecipientController extends Controller
{
    public function destroy(
        Request $request,
        Ticket $ticket,
        TicketCcRecipient $ccRecipient,
        TicketCcService $ccService,
    ): RedirectResponse {
        Gate::authorize('update', $ticket);
        abort_unless($ccRecipient->ticket_id === $ticket->id, 404);

        /** @var User $actor */
        $actor = $request->user();
        $ccService->remove($ticket, $ccRecipient, $actor);

        return back()->with('success', 'CC recipient removed.');
    }
}
