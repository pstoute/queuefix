<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\EscalationRuleRequest;
use App\Models\Department;
use App\Models\EscalationLog;
use App\Models\EscalationRule;
use App\Models\Mailbox;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\EscalationRuleMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EscalationRuleController extends Controller
{
    public function __construct(private EscalationRuleMatcher $matcher) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Escalations/Index', [
            'rules' => EscalationRule::query()
                ->with('creator:id,name')
                ->withCount('logs')
                ->orderBy('name')
                ->get(),
            'logs' => EscalationLog::query()
                ->with([
                    'rule:id,name',
                    'ticket:id,ticket_number,subject',
                    'actionLogs',
                ])
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'tickets' => Ticket::query()
                ->with('customer:id,name')
                ->latest('last_activity_at')
                ->limit(200)
                ->get(['id', 'ticket_number', 'subject', 'customer_id']),
            'statuses' => TicketStatus::query()->ordered()->get(['id', 'name', 'slug', 'is_closed']),
            'priorities' => collect(TicketPriority::cases())->map(fn ($priority) => [
                'value' => $priority->value,
                'label' => $priority->label(),
            ]),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'mailboxes' => Mailbox::query()->orderBy('name')->get(['id', 'name']),
            'agents' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'preview' => $request->session()->get('escalation_preview'),
        ]);
    }

    public function store(EscalationRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        EscalationRule::create([
            ...$validated,
            'is_active' => false,
            'created_by' => $request->user()->id,
            'last_previewed_at' => null,
        ]);

        return back()->with('success', 'Escalation rule created inactive. Preview it before activation.');
    }

    public function update(EscalationRuleRequest $request, EscalationRule $escalationRule): RedirectResponse
    {
        $escalationRule->update([
            ...$request->validated(),
            'is_active' => false,
            'last_previewed_at' => null,
        ]);

        return back()->with('success', 'Escalation rule updated and deactivated until it is previewed again.');
    }

    public function preview(Request $request, EscalationRule $escalationRule): RedirectResponse
    {
        $validated = $request->validate([
            'ticket_id' => ['required', 'uuid', 'exists:tickets,id'],
        ]);
        $ticket = Ticket::query()->findOrFail($validated['ticket_id']);
        $preview = $this->matcher->preview($escalationRule, $ticket);

        $escalationRule->update(['last_previewed_at' => now()]);

        return back()->with('escalation_preview', [
            'rule_id' => $escalationRule->id,
            'rule_name' => $escalationRule->name,
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            ...$preview,
        ]);
    }

    public function setActive(Request $request, EscalationRule $escalationRule): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($validated['is_active'] && $escalationRule->last_previewed_at === null) {
            throw ValidationException::withMessages([
                'is_active' => 'Preview this rule against a ticket before activating it.',
            ]);
        }

        $escalationRule->update(['is_active' => $validated['is_active']]);

        return back()->with('success', $validated['is_active'] ? 'Escalation rule activated.' : 'Escalation rule deactivated.');
    }
}
