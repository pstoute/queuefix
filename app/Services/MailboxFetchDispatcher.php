<?php

namespace App\Services;

use App\Exceptions\MailboxFetchException;
use App\Jobs\FetchEmailsJob;
use App\Models\Mailbox;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class MailboxFetchDispatcher
{
    public function __construct(private MailboxFetchStateService $state) {}

    public function dispatch(Mailbox $mailbox, bool $manual = false): bool
    {
        $claimed = DB::transaction(function () use ($mailbox, $manual): bool {
            $locked = Mailbox::query()->lockForUpdate()->findOrFail($mailbox->id);

            if (! $locked->is_active
                || (! $manual && $locked->next_fetch_at !== null && Carbon::parse($locked->next_fetch_at)->isFuture())) {
                return false;
            }

            $busySince = $locked->fetch_started_at ?? $locked->fetch_queued_at;
            if ($busySince !== null && Carbon::parse($busySince)->isAfter(now()->subMinutes(15))) {
                return false;
            }

            $locked->update([
                'fetch_queued_at' => now(),
                'fetch_started_at' => null,
            ]);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            FetchEmailsJob::dispatch($mailbox->id);
        } catch (Throwable) {
            $failure = MailboxFetchException::dispatch();
            try {
                $this->state->fail($mailbox, $failure);
            } catch (Throwable) {
                try {
                    Mailbox::query()->whereKey($mailbox->id)->update(['fetch_queued_at' => null]);
                } catch (Throwable) {
                    // The controller still receives only the sanitized dispatch failure.
                }
            }

            throw $failure;
        }

        return true;
    }
}
