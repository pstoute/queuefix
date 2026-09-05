<?php

use App\Support\TrustedProxyConfiguration;

return [
    'proxies' => TrustedProxyConfiguration::parse(env('TRUSTED_PROXIES')),
];
