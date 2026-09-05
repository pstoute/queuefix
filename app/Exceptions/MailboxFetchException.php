<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MailboxFetchException extends RuntimeException
{
    public const CATEGORY_AUTHENTICATION = 'authentication';

    public const CATEGORY_TRANSIENT = 'transient';

    public const CATEGORY_PROVIDER = 'provider';

    public const CATEGORY_PROCESSING = 'processing';

    public const CATEGORY_CONFIGURATION = 'configuration';

    public function __construct(
        public readonly string $category,
        public readonly string $errorCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }

    public static function classify(Throwable $exception, string $provider, string $operation): self
    {
        $raw = strtolower($exception->getMessage());
        $status = is_int($exception->getCode()) ? $exception->getCode() : 0;

        if (in_array($status, [401, 403], true)
            || str_contains($raw, 'unauthoriz')
            || str_contains($raw, 'authent')
            || str_contains($raw, 'login failed')
            || str_contains($raw, 'invalid_grant')
            || str_contains($raw, 'credential')
            || str_contains($raw, 'token expired')) {
            return new self(
                self::CATEGORY_AUTHENTICATION,
                "{$provider}_{$operation}_authentication_failed",
                'Authentication must be renewed before this mailbox can be accessed.',
            );
        }

        if ($status === 429
            || $status >= 500
            || str_contains($raw, 'rate limit')
            || str_contains($raw, 'timeout')
            || str_contains($raw, 'temporar')
            || str_contains($raw, 'connection reset')
            || str_contains($raw, 'connection refused')
            || str_contains($raw, "can't connect")
            || str_contains($raw, "couldn't connect")
            || str_contains($raw, 'could not resolve')) {
            return new self(
                self::CATEGORY_TRANSIENT,
                "{$provider}_{$operation}_temporarily_unavailable",
                'The mailbox provider is temporarily unavailable.',
            );
        }

        return new self(
            self::CATEGORY_PROVIDER,
            "{$provider}_{$operation}_failed",
            'The mailbox provider rejected the request.',
        );
    }

    public static function fromHttpStatus(string $provider, string $operation, int $status): self
    {
        if (in_array($status, [401, 403], true) || ($operation === 'token_refresh' && $status === 400)) {
            return new self(
                self::CATEGORY_AUTHENTICATION,
                "{$provider}_{$operation}_authentication_failed",
                'Authentication must be renewed before this mailbox can be accessed.',
            );
        }

        if ($status === 429 || $status >= 500) {
            return new self(
                self::CATEGORY_TRANSIENT,
                "{$provider}_{$operation}_temporarily_unavailable",
                'The mailbox provider is temporarily unavailable.',
            );
        }

        return new self(
            self::CATEGORY_PROVIDER,
            "{$provider}_{$operation}_failed",
            'The mailbox provider rejected the request.',
        );
    }

    public static function processing(): self
    {
        return new self(
            self::CATEGORY_PROCESSING,
            'inbound_processing_failed',
            'A fetched message could not be processed safely.',
        );
    }

    public static function dispatch(): self
    {
        return new self(
            self::CATEGORY_PROCESSING,
            'mailbox_fetch_dispatch_failed',
            'The mailbox fetch could not be queued.',
        );
    }
}
