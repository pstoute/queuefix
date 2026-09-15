<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

final class StaffPasswordLoginRateLimiter
{
    private const DECAY_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Reserve one password attempt, returning a retry delay when limited.
     */
    public function reserve(string $identity, ?string $source, ?string $accountIdentifier): ?int
    {
        $keys = $this->keys($identity, $source, $accountIdentifier);
        $limitedKeys = array_values(array_filter(
            $keys,
            fn (string $key): bool => $this->rateLimiter->tooManyAttempts($key, self::MAX_ATTEMPTS),
        ));

        if ($limitedKeys !== []) {
            return $this->retryAfter($limitedKeys);
        }

        $attempts = array_map(
            fn (string $key): int => $this->rateLimiter->hit($key, self::DECAY_SECONDS),
            $keys,
        );

        if (min($attempts) < 1) {
            return $this->retryAfter($keys);
        }

        if (max($attempts) <= self::MAX_ATTEMPTS) {
            return null;
        }

        return $this->retryAfter($keys);
    }

    public function clear(string $identity, ?string $source, ?string $accountIdentifier): void
    {
        foreach ($this->keys($identity, $source, $accountIdentifier) as $key) {
            $this->rateLimiter->clear($key);
        }
    }

    /**
     * @return list<string>
     */
    public function keys(string $identity, ?string $source, ?string $accountIdentifier): array
    {
        $canonicalIdentity = Str::lower(trim($identity));
        $accountKeyMaterial = $accountIdentifier === null
            ? 'identity:'.$canonicalIdentity
            : 'account:'.$accountIdentifier;

        return [
            'staff-login:source-account:'.hash('sha256', $canonicalIdentity."\0".($source ?? '')),
            'staff-login:identity:'.hash('sha256', $canonicalIdentity),
            'staff-login:account:'.hash('sha256', $accountKeyMaterial),
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function retryAfter(array $keys): int
    {
        return max(1, ...array_map(
            fn (string $key): int => $this->rateLimiter->availableIn($key),
            $keys,
        ));
    }
}
