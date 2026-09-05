<?php

namespace App\Services\Attachments;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

class AttachmentOperationLock
{
    public const NAME = 'queuefix:attachment-storage-operations';

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        $store = (string) config('attachments.operation_lock_store');
        $seconds = max(1, (int) config('attachments.operation_lock_seconds'));
        $waitSeconds = max(0, (int) config('attachments.operation_lock_wait_seconds'));
        $storeBackend = Cache::store($store)->getStore();

        if (! $storeBackend instanceof LockProvider) {
            throw new LogicException("The [{$store}] cache store does not support attachment operation locks.");
        }

        return $storeBackend->lock(self::NAME, $seconds)
            ->block($waitSeconds, $callback);
    }
}
