<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddContentSecurityPolicy
{
    public const POLICY = "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; frame-src 'none'; object-src 'none'; img-src 'self' data: cid: https://*.googleusercontent.com https://graph.microsoft.com; media-src 'none'";

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        return $response;
    }
}
