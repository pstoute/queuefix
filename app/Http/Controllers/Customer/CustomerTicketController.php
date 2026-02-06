<?php

namespace App\Http\Controllers\Customer;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerTicketController extends Controller
{
    public function __construct(
        private TicketService $ticketService,
    ) {}

    public function index(Request $request): Response
    {
        $customer = $this->getCustomer($request);

        $tickets = Ticket::where('customer_id', $customer->id)
            ->with('assignee')
            ->orderBy('last_activity_at', 'desc')
            ->paginate(15);

        return Inertia::render('Customer/Tickets/Index', [
            'tickets' => $tickets,
            'customer' => $customer,
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $customer = $this->getCustomer($request);

        if ($ticket->customer_id !== $customer->id) {
            abort(403);
        }

        $ticket->load([
            'messages' => function ($q) {
                $q->where('type', MessageType::Reply)
                    ->with(['sender', 'attachments'])
                    ->orderBy('created_at', 'asc');
            },
        ]);

        return Inertia::render('Customer/Tickets/Show', [
            'ticket' => $ticket,
            'customer' => $customer,
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $customer = $this->getCustomer($request);

        if ($ticket->customer_id !== $customer->id) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => 'required|string',
        ]);

        $this->ticketService->addMessage($ticket, [
            'type' => MessageType::Reply,
            'body_text' => strip_tags($validated['body']),
            'body_html' => $validated['body'],
            'sender_type' => Customer::class,
            'sender_id' => $customer->id,
        ]);

        return back()->with('success', 'Reply sent.');
    }

    private function getCustomer(Request $request): Customer
    {
        return Customer::findOrFail($request->user('customer')->id);
    }
}
