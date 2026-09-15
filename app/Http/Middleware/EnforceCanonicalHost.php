<?php

namespace App\Http\Middleware;

use App\Support\CanonicalUrlConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

final class EnforceCanonicalHost
{
    /**
     * Reject requests whose effective host differs from the configured application origin.
     *
     * This middleware runs after Laravel's trusted-proxy middleware, so getHost() also
     * validates an X-Forwarded-Host supplied by an explicitly trusted proxy.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalUrl = CanonicalUrlConfiguration::from(config('app.url'));
        $host = $request->getHost();

        if (! hash_equals($canonicalUrl->host, $host)) {
            throw new SuspiciousOperationException(sprintf('Untrusted Host "%s".', $host));
        }

        return $next($request);
    }
}
