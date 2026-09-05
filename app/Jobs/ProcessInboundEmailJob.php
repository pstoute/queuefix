<?php

namespace App\Jobs;

use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MailboxConnectorFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    private const MAX_PROVIDER_IDENTITY_BYTES = 2048;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param array<string, mixed> $providerReference */
    public function __construct(
        private array $providerReference,
        private string $mailboxId,
    ) {
        $allowedKeys = ['provider_message_id', 'provider_remote_id', 'uid_validity'];

        if (array_diff(array_keys($providerReference), $allowedKeys) !== []) {
            throw new InvalidArgumentException('Queued inbound email data must contain provider references only.');
        }

        foreach ($providerReference as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Queued inbound email references must contain scalar values only.');
            }
        }

        foreach (['provider_message_id', 'provider_remote_id'] as $identityKey) {
            $identity = $providerReference[$identityKey] ?? null;

            if (! is_string($identity) && ! is_int($identity)) {
                throw new InvalidArgumentException('Queued inbound email references require string or integer provider identities.');
            }

            $identity = trim((string) $identity);
            if ($identity === ''
                || strlen($identity) > self::MAX_PROVIDER_IDENTITY_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $identity) === 1) {
                throw new InvalidArgumentException('Queued inbound email references require bounded stable provider identities.');
            }
        }

        if (array_key_exists('uid_validity', $providerReference)
            && filter_var($providerReference['uid_validity'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]) === false) {
            throw new InvalidArgumentException('Queued IMAP references require a valid UID epoch.');
        }

        if (! Str::isUuid($mailboxId)) {
            throw new InvalidArgumentException('Queued inbound email references require a valid mailbox UUID.');
        }
    }

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
