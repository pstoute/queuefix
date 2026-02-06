<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoModeMiddleware
{
    /**
     * Route names that are blocked in demo mode.
     */
    private const BLOCKED_ROUTES = [
        'settings.general.update',
        'settings.appearance.update',
        'settings.mailboxes.store',
        'settings.mailboxes.update',
        'settings.mailboxes.destroy',
        'settings.mailboxes.test',
        'settings.sla.store',
        'settings.sla.update',
        'settings.sla.destroy',
        'settings.users.store',
        'settings.users.update',
        'settings.settings.canned-responses.store',
        'settings.settings.canned-responses.update',
        'settings.settings.canned-responses.destroy',
        'profile.update',
        'profile.destroy',
        'password.update',
        'register',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName && in_array($routeName, self::BLOCKED_ROUTES, true)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This action is disabled in demo mode.'], 403);
            }

            return back()->with('error', 'This action is disabled in demo mode.');
        }

        // Block OAuth redirects (these are GET routes so route-name check alone won't catch them)
        if ($request->is('auth/*/redirect')) {
            return back()->with('error', 'OAuth login is disabled in demo mode.');
        }

        return $next($request);
    }
}
