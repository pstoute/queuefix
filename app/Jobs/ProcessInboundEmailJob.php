<?php

namespace App\Jobs;

use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MailboxConnectorFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use UnexpectedValueException;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param array<string, scalar|null> $providerReference */
    public function __construct(
        private array $providerReference,
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
            $connector = $connectorFactory->make($mailbox);

            if (! $connector || ! $connector->connect($mailbox)) {
                throw new RuntimeException('Unable to connect to the mailbox for message processing.');
            }

            $expectedIdentity = trim((string) ($this->providerReference['provider_message_id'] ?? ''));
            if ($expectedIdentity === '') {
                throw new UnexpectedValueException('The queued provider reference is missing its stable identity.');
            }

            $receiptExists = InboundEmailReceipt::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('idempotency_key', hash('sha256', $mailbox->id."\0".$expectedIdentity))
                ->exists();
            $acknowledgementData = $this->providerReference;

            if (! $receiptExists) {
                $emailData = $connector->fetchEmail($this->providerReference);
                $actualIdentity = trim((string) ($emailData['provider_message_id'] ?? ''));

                if ($actualIdentity === '' || ! hash_equals($expectedIdentity, $actualIdentity)) {
                    throw new UnexpectedValueException('The hydrated provider message identity did not match its queued reference.');
                }

                $processor->processInboundEmail($emailData, $mailbox);
                $acknowledgementData = $emailData;
            }

            if (! $connector->acknowledge($acknowledgementData)) {
                throw new RuntimeException('Unable to acknowledge the processed provider message.');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to process inbound email', [
                'mailbox_id' => $this->mailboxId,
                'provider_message_id' => $this->providerReference['provider_message_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
