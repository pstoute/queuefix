<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\SlaTimer;
use App\Models\Ticket;
use App\Models\TicketStatus;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Agent/Dashboard', [
            'statusCounts' => TicketStatus::query()->ordered()->withCount('tickets')->get(),
            'stats' => [
                'unassigned' => Ticket::whereNull('assigned_to')
                    ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                    ->count(),
                'sla_breached' => SlaTimer::where(function ($q) {
                    $q->where('first_response_breached', true)
                        ->orWhere('resolution_breached', true);
                })->whereHas('ticket', function ($q) {
                    $q->whereHas('status', fn ($query) => $query->where('is_closed', false));
                })->count(),
            ],
            'recentTickets' => Ticket::with(['customer', 'assignee', 'status'])
                ->orderBy('last_activity_at', 'desc')
                ->take(10)
                ->get(),
        ]);
    }
}
