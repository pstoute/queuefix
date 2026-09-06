<?php

namespace App\Http\Controllers\Customer;

use App\Enums\AttachmentScanStatus;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Exceptions\AttachmentRejected;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerTicketData;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Attachments\AttachmentService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerTicketController extends Controller
{
    public function __construct(
        private TicketService $ticketService,
        private AttachmentService $attachmentService,
        private CustomerTicketData $ticketData,
    ) {}

    public function index(Request $request): Response
    {
        $customer = $this->getCustomer($request);

        $tickets = Ticket::where('customer_id', $customer->id)
            ->orderBy('last_activity_at', 'desc')
            ->paginate(15)
            ->through(fn (Ticket $ticket): array => $this->ticketData->summary($ticket));

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

        $ticket->messages->each(function ($message): void {
            assert($message instanceof Message);
            $message->attachments->each(function ($attachment): void {
                assert($attachment instanceof Attachment);
                if ($attachment->scan_status === AttachmentScanStatus::Clean) {
                    $attachment->setAttribute('url', route('customer.attachments.download', $attachment));
                }
            });
        });

        return Inertia::render('Customer/Tickets/Show', [
            'ticket' => $this->ticketData->detail($ticket),
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
            'attachments' => 'sometimes|array|max:'.config('attachments.max_files_per_message'),
            'attachments.*' => 'file',
        ]);

        try {
            DB::transaction(function () use ($ticket, $validated, $customer, $request): void {
                $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

                if ($lockedTicket->customer_id !== $customer->id) {
                    abort(403);
                }

                if ($lockedTicket->getRawOriginal('status') === TicketStatus::Closed->value) {
                    abort(409, 'Closed tickets cannot receive customer replies.');
                }

                $message = $this->ticketService->addMessage($lockedTicket, [
                    'type' => MessageType::Reply,
                    'body_text' => strip_tags($validated['body']),
                    'body_html' => $validated['body'],
                    'sender_type' => Customer::class,
                    'sender_id' => $customer->id,
                ]);

                $this->attachmentService->storeForMessage($message, $request->file('attachments', []));
            });
        } catch (AttachmentRejected $exception) {
            throw ValidationException::withMessages(['attachments' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reply sent.');
    }

    private function getCustomer(Request $request): Customer
    {
        return Customer::findOrFail($request->user('customer')->id);
    }
}
