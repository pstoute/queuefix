<?php

use App\Support\TrustedProxyConfiguration;

return [
    'proxies' => TrustedProxyConfiguration::parse(env('TRUSTED_PROXIES')),
    'required' => filter_var(env('TRUSTED_PROXY_REQUIRED', false), FILTER_VALIDATE_BOOL),
];
