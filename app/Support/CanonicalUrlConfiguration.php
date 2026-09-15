<?php

namespace App\Support;

use InvalidArgumentException;
use ValueError;

final readonly class CanonicalUrlConfiguration
{
    private function __construct(
        public string $root,
        public string $scheme,
        public string $host,
    ) {}

    public static function from(?string $value): self
    {
        $url = trim((string) $value);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            self::invalid();
        }

        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            self::invalid();
        }

        if (! is_array($parts)) {
            self::invalid();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            self::invalid();
        }

        $port = $parts['port'] ?? null;
        $isDefaultPort = ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $root = $scheme.'://'.$host;

        if ($port !== null && ! $isDefaultPort) {
            $root .= ':'.$port;
        }

        if ($path !== '') {
            $root .= $path;
        }

        return new self(
            root: $root,
            scheme: $scheme,
            host: $host,
        );
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            'APP_URL must be an absolute HTTP(S) URL without credentials, a query string, or a fragment.'
        );
    }
}
