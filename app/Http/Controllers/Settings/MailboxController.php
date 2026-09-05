<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MailboxType;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Mailbox;
use App\Services\Email\MailboxConnectorFactory;
use App\Services\MailboxFetchDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailboxController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Mailboxes/Index', [
            'mailboxes' => Mailbox::with(['department', 'aliases.department'])->orderBy('name')->get()->map(function ($mailbox) {
                return [
                    'id' => $mailbox->id,
                    'name' => $mailbox->name,
                    'email' => $mailbox->email,
                    'type' => $mailbox->type,
                    'department_id' => $mailbox->department_id,
                    'department' => $mailbox->department,
                    'aliases' => $mailbox->aliases->map(fn ($a) => [
                        'id' => $a->id,
                        'email' => $a->email,
                        'department_id' => $a->department_id,
                        'department' => $a->department,
                    ]),
                    'polling_interval' => $mailbox->polling_interval,
                    'is_active' => $mailbox->is_active,
                    'last_checked_at' => $mailbox->last_checked_at,
                    'last_fetch_attempted_at' => $mailbox->last_fetch_attempted_at,
                    'last_fetch_succeeded_at' => $mailbox->last_fetch_succeeded_at,
                    'provider_cursor' => $mailbox->provider_cursor,
                    'consecutive_fetch_failures' => $mailbox->consecutive_fetch_failures,
                    'last_fetch_error_category' => $mailbox->last_fetch_error_category,
                    'last_fetch_error_code' => $mailbox->last_fetch_error_code,
                    'last_fetch_error_message' => $mailbox->last_fetch_error_message,
                    'next_fetch_at' => $mailbox->next_fetch_at,
                    'last_processing_failed_at' => $mailbox->last_processing_failed_at,
                    'last_processing_error_code' => $mailbox->last_processing_error_code,
                    'last_processing_error_message' => $mailbox->last_processing_error_message,
                    'health_status' => $mailbox->ingestionHealthStatus(),
                    'queue' => [
                        'status' => $mailbox->ingestionQueueStatus(),
                        'queued_at' => $mailbox->fetch_queued_at,
                        'started_at' => $mailbox->fetch_started_at,
                        'pending_messages' => $mailbox->pending_inbound_count,
                        'processing_failures' => $mailbox->consecutive_processing_failures,
                    ],
                ];
            }),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Mailboxes/Create', [
            'types' => collect(MailboxType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => ucfirst($t->value)]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mailboxes,email',
            'type' => 'required|string|in:'.implode(',', array_column(MailboxType::cases(), 'value')),
            'department_id' => 'nullable|exists:departments,id',
            'polling_interval' => 'integer|min:1|max:60',
            'incoming_settings' => 'required_if:type,imap|array',
            'incoming_settings.host' => 'required_if:type,imap|string',
            'incoming_settings.port' => 'required_if:type,imap|integer',
            'incoming_settings.encryption' => 'required_if:type,imap|string|in:ssl,tls,starttls,none',
            'outgoing_settings' => 'nullable|array',
            'outgoing_settings.host' => 'nullable|string',
            'outgoing_settings.port' => 'nullable|integer',
            'outgoing_settings.encryption' => 'nullable|string|in:ssl,tls,starttls,none',
            'credentials' => 'required_if:type,imap|array',
            'credentials.username' => 'required_if:type,imap|string',
            'credentials.password' => 'required_if:type,imap|string',
            'aliases' => 'nullable|array',
            'aliases.*.email' => 'required|email',
            'aliases.*.department_id' => 'required|exists:departments,id',
        ]);

        $mailbox = new Mailbox;
        $mailbox->name = $validated['name'];
        $mailbox->email = $validated['email'];
        $mailbox->type = MailboxType::from($validated['type']);
        $mailbox->department_id = $validated['department_id'] ?? null;
        $mailbox->polling_interval = $validated['polling_interval'] ?? 2;
        $mailbox->incoming_settings = $validated['incoming_settings'] ?? [];
        $mailbox->outgoing_settings = $validated['outgoing_settings'] ?? [];
        $mailbox->is_active = true;

        $mailbox->credentials = $validated['credentials'] ?? [];

        $mailbox->save();

        if (! empty($validated['aliases'])) {
            foreach ($validated['aliases'] as $alias) {
                $mailbox->aliases()->create([
                    'email' => $alias['email'],
                    'department_id' => $alias['department_id'],
                ]);
            }
        }

        return redirect()->route('settings.mailboxes.index')
            ->with('success', 'Mailbox created successfully.');
    }

    public function edit(Mailbox $mailbox): Response
    {
        $mailbox->load(['aliases.department']);

        return Inertia::render('Settings/Mailboxes/Edit', [
            'mailbox' => [
                'id' => $mailbox->id,
                'name' => $mailbox->name,
                'email' => $mailbox->email,
                'type' => $mailbox->type,
                'department_id' => $mailbox->department_id,
                'polling_interval' => $mailbox->polling_interval,
                'is_active' => $mailbox->is_active,
                'incoming_settings' => $mailbox->incoming_settings,
                'outgoing_settings' => $mailbox->outgoing_settings,
                'aliases' => $mailbox->aliases->map(fn ($a) => [
                    'id' => $a->id,
                    'email' => $a->email,
                    'department_id' => $a->department_id,
                ]),
            ],
            'types' => collect(MailboxType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => ucfirst($t->value)]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mailboxes,email,'.$mailbox->id,
            'department_id' => 'nullable|exists:departments,id',
            'polling_interval' => 'integer|min:1|max:60',
            'is_active' => 'boolean',
            'incoming_settings' => 'nullable|array',
            'outgoing_settings' => 'nullable|array',
            'credentials' => 'nullable|array',
            'aliases' => 'nullable|array',
            'aliases.*.email' => 'required|email',
            'aliases.*.department_id' => 'required|exists:departments,id',
        ]);

        $mailbox->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'department_id' => $validated['department_id'] ?? null,
            'polling_interval' => $validated['polling_interval'] ?? $mailbox->polling_interval,
            'is_active' => $validated['is_active'] ?? $mailbox->is_active,
            'incoming_settings' => $validated['incoming_settings'] ?? $mailbox->incoming_settings,
            'outgoing_settings' => $validated['outgoing_settings'] ?? $mailbox->outgoing_settings,
        ]);

        if (! empty($validated['credentials'])) {
            $mailbox->credentials = $validated['credentials'];
            $mailbox->save();
        }

        // Sync aliases: delete existing and recreate
        $mailbox->aliases()->delete();
        if (! empty($validated['aliases'])) {
            foreach ($validated['aliases'] as $alias) {
                $mailbox->aliases()->create([
                    'email' => $alias['email'],
                    'department_id' => $alias['department_id'],
                ]);
            }
        }

        return redirect()->route('settings.mailboxes.index')
            ->with('success', 'Mailbox updated successfully.');
    }

    public function destroy(Mailbox $mailbox): RedirectResponse
    {
        $mailbox->delete();

        return redirect()->route('settings.mailboxes.index')
            ->with('success', 'Mailbox deleted.');
    }

    public function test(Mailbox $mailbox, MailboxConnectorFactory $connectors): RedirectResponse
    {
        if ($mailbox->ingestionQueueStatus() !== 'idle') {
            return back()->with('error', 'Wait for the queued mailbox fetch to finish before testing the connection.');
        }

        try {
            $result = $connectors->resolve($mailbox)->testConnection($mailbox);
        } catch (Throwable) {
            $result = ['success' => false];
        }

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['success'] ? 'Connection test succeeded.' : 'Connection test failed. Review the mailbox health state and credentials.'
        );
    }

    public function fetchNow(Mailbox $mailbox, MailboxFetchDispatcher $dispatcher): RedirectResponse
    {
        try {
            $dispatched = $dispatcher->dispatch($mailbox, manual: true);
        } catch (Throwable) {
            return back()->with('error', 'The mailbox fetch could not be queued.');
        }

        if ($dispatched) {
            return back()->with('success', 'Mailbox fetch queued.');
        }

        return back()->with('error', $mailbox->is_active
            ? 'A mailbox fetch is already queued or running.'
            : 'Activate this mailbox before fetching.');
    }
}
