<?php

namespace App\Jobs;

use App\Exceptions\MailboxFetchException;
use App\Models\Mailbox;
use App\Services\Email\MailboxConnectorFactory;
use App\Services\MailboxFetchStateService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private string $mailboxId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("mailbox-fetch:{$this->mailboxId}"))->dontRelease()->expireAfter(900)];
    }

    public function handle(MailboxConnectorFactory $connectors, MailboxFetchStateService $state): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if (! $mailbox || ! $mailbox->is_active) {
            Mailbox::query()->whereKey($this->mailboxId)->update([
                'fetch_queued_at' => null,
                'fetch_started_at' => null,
            ]);

            return;
        }

        $mailbox = $state->start($mailbox);

        try {
            $connector = $connectors->resolve($mailbox);
            if (! $connector->connect($mailbox)) {
                throw $connector->lastFailure() ?? new MailboxFetchException(
                    MailboxFetchException::CATEGORY_PROVIDER,
                    ((string) $mailbox->getRawOriginal('type')).'_connection_failed',
                    'The mailbox connection failed.',
                );
            }

            $lastSuccess = $mailbox->last_fetch_succeeded_at ?? $mailbox->last_checked_at;
            $emails = $connector->fetchNewEmails($lastSuccess !== null ? Carbon::parse($lastSuccess) : null);
            foreach ($emails as $emailData) {
                $mailbox->increment('pending_inbound_count');

                try {
                    ProcessInboundEmailJob::dispatch($emailData, $mailbox->id);
                } catch (Throwable) {
                    Mailbox::query()->whereKey($mailbox->id)->update([
                        'pending_inbound_count' => DB::raw('CASE WHEN pending_inbound_count > 0 THEN pending_inbound_count - 1 ELSE 0 END'),
                    ]);

                    throw MailboxFetchException::processing();
                }
            }

            $state->succeed($mailbox, $connector->providerCursor());

            Log::info('Mailbox fetch completed', [
                'mailbox_id' => $mailbox->id,
                'fetched_count' => count($emails),
            ]);
        } catch (Throwable $exception) {
            $failure = $exception instanceof MailboxFetchException
                ? $exception
                : MailboxFetchException::classify($exception, (string) $mailbox->getRawOriginal('type'), 'fetch');
            $retryAt = $state->fail($mailbox, $failure);

            Log::warning('Mailbox fetch failed', [
                'mailbox_id' => $mailbox->id,
                'error_category' => $failure->category,
                'error_code' => $failure->errorCode,
                'retry_at' => $retryAt->format(DATE_ATOM),
            ]);
        }
    }
}
