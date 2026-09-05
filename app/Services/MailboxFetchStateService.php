<?php

namespace App\Services;

use App\Exceptions\MailboxFetchException;
use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;

class MailboxFetchStateService
{
    public function start(Mailbox $mailbox): Mailbox
    {
        return DB::transaction(function () use ($mailbox): Mailbox {
            $locked = Mailbox::query()->lockForUpdate()->findOrFail($mailbox->id);
            $locked->update([
                'last_fetch_attempted_at' => now(),
                'fetch_queued_at' => null,
                'fetch_started_at' => now(),
            ]);

            return $locked;
        });
    }

    public function succeed(Mailbox $mailbox, ?string $providerCursor): void
    {
        $nextFetchAt = now()->addMinutes($mailbox->polling_interval);

        $mailbox->update([
            'last_checked_at' => now(),
            'last_fetch_succeeded_at' => now(),
            'provider_cursor' => $providerCursor ?? $mailbox->provider_cursor,
            'consecutive_fetch_failures' => 0,
            'last_fetch_error_category' => null,
            'last_fetch_error_code' => null,
            'last_fetch_error_message' => null,
            'next_fetch_at' => $nextFetchAt,
            'fetch_queued_at' => null,
            'fetch_started_at' => null,
        ]);
    }

    public function fail(Mailbox $mailbox, MailboxFetchException $failure): \DateTimeInterface
    {
        return DB::transaction(function () use ($mailbox, $failure): \DateTimeInterface {
            $locked = Mailbox::query()->lockForUpdate()->findOrFail($mailbox->id);
            $failureCount = $locked->consecutive_fetch_failures + 1;
            $retryAt = now()->addMinutes($this->retryDelayMinutes($failure->category, $failureCount));
            $locked->update([
                'consecutive_fetch_failures' => $failureCount,
                'last_fetch_error_category' => $failure->category,
                'last_fetch_error_code' => $failure->errorCode,
                'last_fetch_error_message' => $failure->getMessage(),
                'next_fetch_at' => $retryAt,
                'fetch_queued_at' => null,
                'fetch_started_at' => null,
            ]);

            return $retryAt;
        });
    }

    public function recordProcessingSuccess(Mailbox $mailbox): void
    {
        Mailbox::query()->whereKey($mailbox->id)->update([
            'pending_inbound_count' => DB::raw('CASE WHEN pending_inbound_count > 0 THEN pending_inbound_count - 1 ELSE 0 END'),
            'consecutive_processing_failures' => 0,
            'last_processing_succeeded_at' => now(),
            'last_processing_error_code' => null,
            'last_processing_error_message' => null,
        ]);
    }

    public function recordProcessingFailure(string $mailboxId): void
    {
        Mailbox::query()->whereKey($mailboxId)->update([
            'pending_inbound_count' => DB::raw('CASE WHEN pending_inbound_count > 0 THEN pending_inbound_count - 1 ELSE 0 END'),
            'consecutive_processing_failures' => DB::raw('consecutive_processing_failures + 1'),
            'last_processing_failed_at' => now(),
            'last_processing_error_code' => 'inbound_processing_failed',
            'last_processing_error_message' => 'A fetched message could not be processed safely.',
        ]);
    }

    public function retryDelayMinutes(string $category, int $failureCount): int
    {
        return match ($category) {
            MailboxFetchException::CATEGORY_TRANSIENT => min(240, 5 * (2 ** min(6, max(0, $failureCount - 1)))),
            MailboxFetchException::CATEGORY_PROVIDER => min(360, 15 * (2 ** min(5, max(0, $failureCount - 1)))),
            MailboxFetchException::CATEGORY_PROCESSING => min(120, 5 * (2 ** min(5, max(0, $failureCount - 1)))),
            MailboxFetchException::CATEGORY_AUTHENTICATION,
            MailboxFetchException::CATEGORY_CONFIGURATION => 60,
            default => 15,
        };
    }
}
