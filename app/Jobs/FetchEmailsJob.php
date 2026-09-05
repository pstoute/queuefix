<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Email\InboundEmailClaimService;
use App\Services\Email\InboundEmailPollingPolicy;
use App\Services\Email\MailboxConnectorFactory;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchEmailsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 300;

    public function __construct(
        private string $mailboxId,
    ) {}

    public function uniqueId(): string
    {
        return $this->mailboxId;
    }

    public function handle(
        MailboxConnectorFactory $connectorFactory,
        ?InboundEmailClaimService $claimService = null,
        ?InboundEmailPollingPolicy $pollingPolicy = null,
        ?Dispatcher $dispatcher = null,
    ): void {
        $claimService ??= app(InboundEmailClaimService::class);
        $pollingPolicy ??= app(InboundEmailPollingPolicy::class);
        $dispatcher ??= app(Dispatcher::class);
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
        $considered = 0;
        $dispatched = 0;
        foreach ($connector->fetchNewEmailReferences($lastCheckedAt) as $providerReference) {
            if ($considered >= $pollingPolicy->scanSize() || $dispatched >= $pollingPolicy->batchSize()) {
                break;
            }
            $considered++;

            $claimToken = (string) Str::uuid();

            try {
                $processingJob = new ProcessInboundEmailJob($providerReference, $mailbox->id, $claimToken);
                $providerMessageId = $processingJob->providerMessageId();

                if (! $claimService->acquire($mailbox->id, $providerMessageId, $claimToken)) {
                    continue;
                }

                try {
                    $dispatcher->dispatch($processingJob);
                    $dispatched++;
                } catch (\Throwable $exception) {
                    // Dispatch outcomes are not reliably knowable. Retain the
                    // claim so an accepted job cannot be enqueued a second time;
                    // a truly failed dispatch is recovered after lease expiry.
                    throw $exception;
                }
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
