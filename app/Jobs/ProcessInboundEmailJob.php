<?php

namespace App\Jobs;

use App\Exceptions\MailboxFetchException;
use App\Models\Mailbox;
use App\Services\Email\EmailProcessorService;
use App\Services\MailboxFetchStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private array $emailData,
        private string $mailboxId,
    ) {}

    public function handle(EmailProcessorService $processor, MailboxFetchStateService $state): void
    {
        $mailbox = Mailbox::find($this->mailboxId);

        if (! $mailbox) {
            Log::error('Mailbox not found for inbound email', ['mailbox_id' => $this->mailboxId]);

            return;
        }

        try {
            $processor->processInboundEmail($this->emailData, $mailbox);
            $state->recordProcessingSuccess($mailbox);
        } catch (\Throwable) {
            Log::error('Failed to process inbound email', [
                'mailbox_id' => $this->mailboxId,
                'error_code' => 'inbound_processing_failed',
            ]);

            throw MailboxFetchException::processing();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(MailboxFetchStateService::class)->recordProcessingFailure($this->mailboxId);
    }
}
