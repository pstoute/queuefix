<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Email\MailboxConnectorFactory;
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

    public function handle(MailboxConnectorFactory $connectorFactory): void
    {
        $mailbox = Mailbox::find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            return;
        }

        $connector = $connectorFactory->make($mailbox);

        if (! $connector) {
            Log::error('No connector available for mailbox type', [
                'mailbox_id' => $mailbox->id,
                'type' => $mailbox->getRawOriginal('type'),
            ]);

            return;
        }

        if (! $connector->connect($mailbox)) {
            Log::error('Failed to connect to mailbox', ['mailbox_id' => $mailbox->id]);

            return;
        }

        /** @var \DateTimeInterface|null $lastCheckedAt */
        $lastCheckedAt = $mailbox->last_checked_at;
        foreach ($connector->fetchNewEmailReferences($lastCheckedAt) as $providerReference) {
            try {
                ProcessInboundEmailJob::dispatch($providerReference, $mailbox->id);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch email processing', [
                    'mailbox_id' => $mailbox->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        $mailbox->update(['last_checked_at' => now()]);
    }
}
