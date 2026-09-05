<?php

namespace App\Services;

use Illuminate\Support\Str;

class EmailAddressNormalizer
{
    /**
     * @return list<array{email: string, display_name: string|null}>
     */
    public function normalize(mixed $input): array
    {
        $addresses = [];

        foreach ($this->flatten($input) as $candidate) {
            $parsed = $this->parse($candidate);

            if ($parsed !== null) {
                $addresses[$parsed['email']] ??= $parsed;
            }
        }

        return array_slice(array_values($addresses), 0, 50);
    }

    /** @return list<mixed> */
    private function flatten(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_string($input)) {
            return str_getcsv($input, ',', '"', '\\');
        }

        if (! is_array($input)) {
            return [];
        }

        if (array_key_exists('email', $input) || array_key_exists('address', $input)) {
            return [$input];
        }

        $flattened = [];
        foreach ($input as $value) {
            array_push($flattened, ...$this->flatten($value));
        }

        return $flattened;
    }

    /** @return array{email: string, display_name: string|null}|null */
    private function parse(mixed $candidate): ?array
    {
        $displayName = null;

        if (is_array($candidate)) {
            $email = $candidate['email'] ?? $candidate['address'] ?? null;
            $displayName = $candidate['display_name'] ?? $candidate['name'] ?? null;
        } elseif (is_string($candidate)) {
            $value = trim($candidate);
            if (preg_match('/^(.*?)<([^<>]+)>$/', $value, $matches) === 1) {
                $displayName = trim($matches[1], " \t\n\r\0\x0B\"");
                $email = trim($matches[2]);
            } else {
                $email = $value;
            }
        } else {
            return null;
        }

        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));
        if (
            strlen($email) > 254
            || str_contains($email, "\r")
            || str_contains($email, "\n")
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return null;
        }

        if (! is_string($displayName) || trim($displayName) === '') {
            $displayName = null;
        } else {
            $displayName = Str::limit(str_replace(["\r", "\n"], '', trim($displayName)), 255, '');
        }

        return ['email' => $email, 'display_name' => $displayName];
    }
}
