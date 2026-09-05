<?php

namespace App\Http\Controllers\Agent;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Jobs\SendEmailReplyJob;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\SlaService;
use App\Services\TicketCcService;
use App\Services\TicketMentionService;
use App\Services\TicketMergeService;
use App\Services\TicketReadStateService;
use App\Services\TicketService;
use App\Services\TicketSplitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(
        private TicketService $ticketService,
        private SlaService $slaService,
        private TicketReadStateService $readStateService,
        private TicketMentionService $mentionService,
        private TicketCcService $ccService,
        private TicketMergeService $mergeService,
        private TicketSplitService $splitService,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $query = Ticket::with(['customer', 'assignee', 'department', 'tags', 'status', 'slaTimer.slaPolicy'])
            ->whereNull('merged_into_ticket_id')
            ->orderBy('last_activity_at', 'desc');
        $this->readStateService->addUnreadCount($query, $user);

        if ($request->filled('status')) {
            $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('slug', $request->status));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('department')) {
            $query->where('department_id', $request->department);
        }

        if ($request->filled('assigned_to')) {
            if ($request->assigned_to === 'unassigned') {
                $query->whereNull('assigned_to');
            } elseif ($request->assigned_to === 'me') {
                $query->where('assigned_to', $user->id);
            } else {
                $query->where('assigned_to', $request->assigned_to);
            }
        }

        if ($request->boolean('watching')) {
            $query->whereHas('watchers', fn ($watcherQuery) => $watcherQuery->whereKey($user->id));
        }

        if ($request->boolean('unread')) {
            $this->readStateService->applyUnreadTicketConstraint($query, $user);
        }

        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(subject) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(ticket_number) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                    });
            });
        }

        $tickets = $query->paginate(25)->withQueryString();

        return Inertia::render('Agent/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['status', 'priority', 'assigned_to', 'department', 'search', 'watching', 'unread']),
            'agents' => User::where('is_active', true)->select('id', 'name', 'email', 'avatar')->get(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'statuses' => TicketStatus::query()->ordered()->get(),
            'statusCounts' => TicketStatus::query()->ordered()->withCount([
                'tickets' => fn ($tickets) => $tickets->whereNull('merged_into_ticket_id'),
            ])->get(),
            'unassignedCount' => Ticket::whereNull('assigned_to')
                ->whereNull('merged_into_ticket_id')
                ->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', false))
                ->count(),
            'unreadCount' => $this->readStateService->unreadTicketCount($user),
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response|RedirectResponse
    {
        Gate::authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();
        if ($ticket->isMerged()) {
            $target = $ticket->canonicalTicket();
            Gate::forUser($user)->authorize('view', $target);

            return redirect()->route('agent.tickets.show', $target)
                ->with('success', "Ticket {$ticket->ticket_number} was merged into {$target->ticket_number}.");
        }

        $ticket->load([
            'customer',
            'assignee',
            'department',
            'tags',
            'ccRecipients' => fn ($query) => $query
                ->where('validation_state', 'approved')
                ->whereNull('removed_at')
                ->orderBy('email'),
            'mailbox',
            'rating.customer:id,name',
            'status',
            'slaTimer.slaPolicy',
            'slaTimer.pauseIntervals',
            'watchers' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')
                ->select('users.id', 'name', 'email', 'avatar'),
            'messages' => function ($q) {
                $q->with([
                    'sender',
                    'attachments',
                    'originalTicket:id,ticket_number',
                    'ccRecipients' => fn ($ccQuery) => $ccQuery->orderBy('email'),
                    'mentions' => fn ($mentionQuery) => $mentionQuery
                        ->whereNull('removed_at')
                        ->with('mentionedUser:id,handle'),
                ])
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'mergeEvents.actor:id,name',
            'mergeEvents.counterpartTicket:id,ticket_number',
            'splitEvents.actor:id,name',
            'splitEvents.counterpartTicket:id,ticket_number',
        ]);

        /** @var Message|null $latestMessage */
        $latestMessage = $ticket->messages->last();
        $this->readStateService->markRead($ticket, $user, $latestMessage);

        if ($ticket->slaTimer) {
            $ticket->slaTimer->setAttribute('status_summary', $this->slaService->getSlaStatus($ticket->slaTimer));
        }

        $ticket->setAttribute('is_watching', $ticket->watchers->contains('id', $user->id));
        $ticket->setAttribute('unread_count', 0);

        return Inertia::render('Agent/Tickets/Show', [
            'ticket' => $ticket,
            'agents' => User::where('is_active', true)->select('id', 'name', 'email', 'avatar')->get(),
            'statuses' => TicketStatus::query()->ordered()->get(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
            'mentionableUsers' => User::query()
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'handle', 'avatar', 'is_active'])
                ->filter(fn (User $candidate): bool => Gate::forUser($candidate)->allows('view', $ticket))
                ->values(),
            'canMerge' => Gate::forUser($user)->allows('merge', $ticket),
            'canSplit' => Gate::forUser($user)->allows('split', $ticket),
            'mergeCandidates' => Gate::forUser($user)->allows('merge', $ticket)
                ? Ticket::query()
                    ->whereKeyNot($ticket->id)
                    ->where('customer_id', $ticket->customer_id)
                    ->whereNull('merged_into_ticket_id')
                    ->latest('last_activity_at')
                    ->limit(100)
                    ->get(['id', 'ticket_number', 'subject'])
                : [],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Agent/Tickets/Create', [
            'agents' => User::where('is_active', true)->select('id', 'name', 'email', 'avatar')->get(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'priority' => 'sometimes|string|in:'.implode(',', array_column(TicketPriority::cases(), 'value')),
            'assigned_to' => 'nullable|exists:users,id',
            'customer_email' => 'required|email',
            'customer_name' => 'required|string|max:255',
        ]);

        $customer = Customer::firstOrCreate(
            ['email' => strtolower($validated['customer_email'])],
            ['name' => $validated['customer_name']]
        );

        $ticket = $this->ticketService->createTicket($validated, $customer, creator: $request->user());

        return redirect()->route('agent.tickets.show', $ticket)
            ->with('success', 'Ticket created successfully.');
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        $validated = $request->validate([
            'body' => 'required|string',
            'type' => 'sometimes|string|in:reply,internal_note',
            'cc' => [
                'sometimes',
                'array',
                'max:20',
                Rule::prohibitedIf($request->input('type', MessageType::Reply->value) === MessageType::InternalNote->value),
            ],
            'cc.*' => ['required', 'string', 'max:254', 'email:rfc'],
        ]);

        $type = MessageType::from($validated['type'] ?? 'reply');
        $isInternalNote = $type === MessageType::InternalNote;
        /** @var User $actor */
        $actor = $request->user();

        $messageData = [
            'type' => $type,
            'body_text' => $validated['body'],
            'body_html' => null,
            'sender_type' => User::class,
            'sender_id' => $actor->id,
        ];

        if ($isInternalNote) {
            $message = $this->ticketService->addMessage($ticket, $messageData, actor: $actor);
            $this->mentionService->syncMentions($ticket, $message, $actor);
        } else {
            $message = DB::transaction(function () use ($ticket, $messageData, $validated, $actor): Message {
                $message = $this->ticketService->addMessage($ticket, $messageData, actor: $actor);
                $this->ccService->recordStaffReply($ticket, $message, $validated['cc'] ?? [], $actor);

                return $message;
            }, 3);
        }

        if ($type === MessageType::Reply && $ticket->mailbox_id) {
            SendEmailReplyJob::dispatch($ticket->id, $message->id);
        }

        return back()->with('success', $type === MessageType::InternalNote ? 'Note added.' : 'Reply sent.');
    }

    public function updateStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::exists('ticket_statuses', 'slug')->whereNull('deleted_at'),
            ],
        ]);

        $status = TicketStatus::query()->where('slug', $validated['status'])->firstOrFail();
        $this->ticketService->updateStatus($ticket, $status, $request->user());

        return back()->with('success', 'Status updated.');
    }

    public function updatePriority(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        $validated = $request->validate([
            'priority' => 'required|string|in:'.implode(',', array_column(TicketPriority::cases(), 'value')),
        ]);

        $priority = TicketPriority::from($validated['priority']);
        if ($ticket->getRawOriginal('priority') !== $priority->value) {
            $ticket->update([
                'priority' => $priority,
                'priority_changed_at' => now(),
            ]);
        }

        return back()->with('success', 'Priority updated.');
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('update', $ticket);

        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $agent = $validated['assigned_to'] ? User::find($validated['assigned_to']) : null;
        $this->ticketService->assignTicket($ticket, $agent, $request->user());

        return back()->with('success', $agent ? "Assigned to {$agent->name}." : 'Unassigned.');
    }

    public function merge(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('merge', $ticket);

        $validated = $request->validate([
            'merge_ticket_id' => ['required', 'uuid', 'exists:tickets,id'],
        ]);

        $source = Ticket::findOrFail($validated['merge_ticket_id']);
        Gate::authorize('merge', $source);
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->mergeService->merge($source, $ticket, $actor);

        return redirect()->route('agent.tickets.show', $target)
            ->with('success', "Ticket {$source->ticket_number} merged into this ticket.");
    }

    public function split(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('split', $ticket);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['required', 'uuid', 'distinct', 'exists:messages,id'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $newTicket = $this->splitService->split(
            $ticket,
            $validated['message_ids'],
            $validated['subject'],
            $actor,
        );

        return redirect()->route('agent.tickets.show', $newTicket)
            ->with('success', "Selected messages were split from {$ticket->ticket_number} into this ticket.");
    }
}
