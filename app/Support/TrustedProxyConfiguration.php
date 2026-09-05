<?php

namespace App\Support;

use InvalidArgumentException;

final class TrustedProxyConfiguration
{
    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        $proxies = array_values(array_filter(
            array_map('trim', explode(',', (string) $value))
        ));

        foreach ($proxies as $proxy) {
            if (in_array($proxy, ['*', '**', 'REMOTE_ADDR'], true)) {
                self::invalid();
            }

            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            $isIpv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isIpv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            if (! $isIpv4 && ! $isIpv6) {
                self::invalid();
            }

            if ($prefix === null) {
                continue;
            }

            $maximumPrefix = $isIpv4 ? 32 : 128;

            if (! preg_match('/^\d+$/', $prefix) || (int) $prefix < 1 || (int) $prefix > $maximumPrefix) {
                self::invalid();
            }
        }

        return $proxies;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            'TRUSTED_PROXIES must contain explicit proxy IP addresses or narrowly scoped CIDRs.'
        );
    }
}
