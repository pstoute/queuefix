<?php

namespace App\Services\Email;

final class InboundEmailPollingPolicy
{
    public const MAX_POLL_BATCH_SIZE = 100;

    public const MAX_POLL_SCAN_SIZE = 400;

    public function batchSize(): int
    {
        return min(self::MAX_POLL_BATCH_SIZE, max(1, (int) config('inbound.poll_batch_size')));
    }

    public function scanSize(): int
    {
        return min(self::MAX_POLL_SCAN_SIZE, $this->batchSize() * 4);
    }

    public function claimLeaseSeconds(): int
    {
        return min(86_400, max(120, (int) config('inbound.claim_lease_seconds')));
    }

    public function retryDelaySeconds(int $failureCount): int
    {
        $base = min(86_400, max(60, (int) config('inbound.retry_base_seconds')));
        $maximum = min(86_400, max($base, (int) config('inbound.retry_max_seconds')));
        $exponent = min(10, max(0, $failureCount - 1));

        return min($maximum, $base * (2 ** $exponent));
    }

    public function maxFailureCount(): int
    {
        return min(20, max(1, (int) config('inbound.max_failure_count')));
    }
}
