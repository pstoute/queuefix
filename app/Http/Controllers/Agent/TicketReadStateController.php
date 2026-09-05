<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketReadStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketReadStateController extends Controller
{
    public function __invoke(
        Request $request,
        Ticket $ticket,
        TicketReadStateService $readStateService,
    ): RedirectResponse {
        Gate::authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();
        $readStateService->markRead($ticket, $user, $readStateService->latestVisibleMessage($ticket, $user));

        return back()->with('success', 'Ticket marked as read.');
    }
}
