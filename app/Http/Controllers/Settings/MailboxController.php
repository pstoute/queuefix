<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MailboxType;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
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
            'mailboxes' => Mailbox::orderBy('name')->get()->map(function ($mailbox) {
                return [
                    'id' => $mailbox->id,
                    'name' => $mailbox->name,
                    'email' => $mailbox->email,
                    'type' => $mailbox->type,
                    'department' => $mailbox->department,
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mailboxes,email',
            'type' => 'required|string|in:' . implode(',', array_column(MailboxType::cases(), 'value')),
            'department' => 'nullable|string|max:255',
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
        ]);

        $mailbox = new Mailbox();
        $mailbox->name = $validated['name'];
        $mailbox->email = $validated['email'];
        $mailbox->type = MailboxType::from($validated['type']);
        $mailbox->department = $validated['department'] ?? null;
        $mailbox->polling_interval = $validated['polling_interval'] ?? 2;
        $mailbox->incoming_settings = $validated['incoming_settings'] ?? [];
        $mailbox->outgoing_settings = $validated['outgoing_settings'] ?? [];
        $mailbox->is_active = true;

        if (! empty($validated['credentials'])) {
            $mailbox->credentials = $validated['credentials'];
        }

        $mailbox->save();

        return redirect()->route('settings.mailboxes.index')
            ->with('success', 'Mailbox created successfully.');
    }

    public function edit(Mailbox $mailbox): Response
    {
        return Inertia::render('Settings/Mailboxes/Edit', [
            'mailbox' => [
                'id' => $mailbox->id,
                'name' => $mailbox->name,
                'email' => $mailbox->email,
                'type' => $mailbox->type,
                'department' => $mailbox->department,
                'polling_interval' => $mailbox->polling_interval,
                'is_active' => $mailbox->is_active,
                'incoming_settings' => $mailbox->incoming_settings,
                'outgoing_settings' => $mailbox->outgoing_settings,
            ],
            'types' => collect(MailboxType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => ucfirst($t->value)]),
        ]);
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mailboxes,email,' . $mailbox->id,
            'department' => 'nullable|string|max:255',
            'polling_interval' => 'integer|min:1|max:60',
            'is_active' => 'boolean',
            'incoming_settings' => 'nullable|array',
            'outgoing_settings' => 'nullable|array',
            'credentials' => 'nullable|array',
        ]);

        $mailbox->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'department' => $validated['department'] ?? null,
            'polling_interval' => $validated['polling_interval'] ?? $mailbox->polling_interval,
            'is_active' => $validated['is_active'] ?? $mailbox->is_active,
            'incoming_settings' => $validated['incoming_settings'] ?? $mailbox->incoming_settings,
            'outgoing_settings' => $validated['outgoing_settings'] ?? $mailbox->outgoing_settings,
        ]);

        if (! empty($validated['credentials'])) {
            $mailbox->credentials = $validated['credentials'];
            $mailbox->save();
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
