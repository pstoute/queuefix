<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketWatcherController extends Controller
{
    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('watch', $ticket);

        /** @var User $user */
        $user = $request->user();

        try {
            $user->watchedTickets()->syncWithoutDetaching([$ticket->id]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request already created the same unique subscription.
        }

        return back()->with('success', 'You are now watching this ticket.');
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('watch', $ticket);

        /** @var User $user */
        $user = $request->user();
        $user->watchedTickets()->detach($ticket->id);

        return back()->with('success', 'You are no longer watching this ticket.');
    }
}
