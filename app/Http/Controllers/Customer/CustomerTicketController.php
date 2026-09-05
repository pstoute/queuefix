<?php

namespace App\Http\Controllers\Customer;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Services\TicketCcService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CustomerTicketController extends Controller
{
    public function __construct(
        private TicketService $ticketService,
        private TicketCcService $ccService,
    ) {}

    public function index(Request $request): Response
    {
        $customer = $this->getCustomer($request);

        $query = Ticket::where('customer_id', $customer->id)
            ->with(['assignee', 'status'])
            ->orderBy('last_activity_at', 'desc');

        if ($request->filled('status')) {
            $query->whereHas('status', function ($statusQuery) use ($request): void {
                $statusQuery
                    ->where('is_customer_visible', true)
                    ->where('slug', $request->status);
            });
        }

        $tickets = $query->paginate(15)->withQueryString();
        $tickets->getCollection()->each($this->prepareForCustomer(...));

        return Inertia::render('Customer/Tickets/Index', [
            'tickets' => $tickets,
            'customer' => $customer,
            'statuses' => TicketStatus::query()
                ->customerVisible()
                ->ordered()
                ->get(['id', 'name', 'slug', 'color', 'is_closed']),
            'filters' => $request->only('status'),
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
                    ->with(['sender', 'attachments', 'ccRecipients'])
                    ->orderBy('created_at', 'asc');
            },
            'ccRecipients' => fn ($query) => $query
                ->where('validation_state', 'approved')
                ->whereNull('removed_at')
                ->orderBy('email'),
            'rating',
            'status',
        ]);

        $this->prepareForCustomer($ticket);

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

        if ($ticket->status()->firstOrFail()->is_closed) {
            abort(422, 'Closed tickets cannot receive replies.');
        }

        $validated = $request->validate([
            'body' => 'required|string',
            'cc_recipient_ids' => ['sometimes', 'array', 'max:20'],
            'cc_recipient_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        DB::transaction(function () use ($ticket, $validated, $customer): void {
            $message = $this->ticketService->addMessage($ticket, [
                'type' => MessageType::Reply,
                'body_text' => strip_tags($validated['body']),
                'body_html' => $validated['body'],
                'sender_type' => Customer::class,
                'sender_id' => $customer->id,
            ]);

            $this->ccService->recordCustomerReply(
                $ticket,
                $message,
                $validated['cc_recipient_ids'] ?? [],
                $customer,
            );
        }, 3);

        return back()->with('success', 'Reply sent.');
    }

    private function getCustomer(Request $request): Customer
    {
        return Customer::findOrFail($request->user('customer')->id);
    }

    private function prepareForCustomer(Ticket $ticket): void
    {
        $status = $ticket->status;
        $customerStatus = $status && $status->is_customer_visible
            ? [
                'name' => $status->name,
                'slug' => $status->slug,
                'color' => $status->color,
                'is_closed' => $status->is_closed,
            ]
            : [
                'name' => $status?->is_closed ? 'Closed' : 'In Progress',
                'color' => '#6b7280',
                'is_closed' => (bool) $status?->is_closed,
            ];

        $ticket->setAttribute('customer_status', $customerStatus);
        $ticket->makeHidden(['ticket_status_id']);
        $ticket->unsetRelation('status');
    }
}
