<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddStrictTransportSecurity
{
    public const POLICY = 'max-age=31536000';

    /**
     * Add HSTS only when Laravel has authenticated the request as HTTPS.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::POLICY);
        }

        return $response;
    }
}
