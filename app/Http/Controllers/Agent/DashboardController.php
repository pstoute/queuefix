<?php

namespace App\Http\Controllers\Agent;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\SlaTimer;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Agent/Dashboard', [
            'stats' => [
                'open' => Ticket::where('status', TicketStatus::Open)->count(),
                'pending' => Ticket::where('status', TicketStatus::Pending)->count(),
                'on_hold' => Ticket::where('status', TicketStatus::OnHold)->count(),
                'resolved_today' => Ticket::where('status', TicketStatus::Resolved)
                    ->whereDate('updated_at', today())
                    ->count(),
                'unassigned' => Ticket::whereNull('assigned_to')
                    ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
                    ->count(),
                'sla_breached' => SlaTimer::where(function ($q) {
                    $q->where('first_response_breached', true)
                        ->orWhere('resolution_breached', true);
                })->whereHas('ticket', function ($q) {
                    $q->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed]);
                })->count(),
            ],
            'recentTickets' => Ticket::with(['customer', 'assignee'])
                ->orderBy('last_activity_at', 'desc')
                ->take(10)
                ->get(),
        ]);
    }
}
