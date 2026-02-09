<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MailboxType;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Mailbox;
use App\Models\MailboxAlias;
use App\Services\Email\GmailConnector;
use App\Services\Email\ImapConnector;
use App\Services\Email\MicrosoftGraphConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
            'type' => 'required|string|in:' . implode(',', array_column(MailboxType::cases(), 'value')),
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

        $mailbox = new Mailbox();
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
            'email' => 'required|email|unique:mailboxes,email,' . $mailbox->id,
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

    public function test(Mailbox $mailbox): RedirectResponse
    {
        $connector = match ($mailbox->type) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
        };

        $result = $connector->testConnection($mailbox);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }
}
