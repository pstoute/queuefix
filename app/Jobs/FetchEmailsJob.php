<?php

namespace App\Jobs;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\GmailConnector;
use App\Services\Email\ImapConnector;
use App\Services\Email\MicrosoftGraphConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FetchEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private string $mailboxId,
    ) {}

    public function handle(EmailProcessorService $processor): void
    {
        $mailbox = Mailbox::find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }

        $connector = $this->getConnector($mailbox);

        if (! $connector) {
            Log::error('No connector available for mailbox type', [
                'mailbox_id' => $mailbox->id,
                'type' => $mailbox->type->value,
            ]);

            return;
        }

        if (! $connector->connect($mailbox)) {
            Log::error('Failed to connect to mailbox', ['mailbox_id' => $mailbox->id]);

            return;
        }

        $emails = $connector->fetchNewEmails($mailbox->last_checked_at);

        foreach ($emails as $emailData) {
            try {
                ProcessInboundEmailJob::dispatch($emailData, $mailbox->id);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch email processing', [
                    'mailbox_id' => $mailbox->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mailbox->update(['last_checked_at' => now()]);
    }

    private function getConnector(Mailbox $mailbox): ImapConnector|GmailConnector|MicrosoftGraphConnector|null
    {
        return match ($mailbox->type) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
        };
    }
}
