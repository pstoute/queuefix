<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MailboxConnectorFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private array $emailData,
        private string $mailboxId,
    ) {}

    public function handle(EmailProcessorService $processor, MailboxConnectorFactory $connectorFactory): void
    {
        $mailbox = Mailbox::find($this->mailboxId);

        if (! $mailbox) {
            Log::error('Mailbox not found for inbound email', ['mailbox_id' => $this->mailboxId]);

            return;
        }

        try {
            $processor->processInboundEmail($this->emailData, $mailbox);

            $connector = $connectorFactory->make($mailbox);

            if (! $connector || ! $connector->connect($mailbox)) {
                throw new RuntimeException('Unable to reconnect to the mailbox for acknowledgement.');
            }

            if (! $connector->acknowledge($this->emailData)) {
                throw new RuntimeException('Unable to acknowledge the processed provider message.');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to process inbound email', [
                'mailbox_id' => $this->mailboxId,
                'subject' => $this->emailData['subject'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
