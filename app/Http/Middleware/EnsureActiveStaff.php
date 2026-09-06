<?php

namespace App\Http\Middleware;

use App\Services\Auth\StaffAuthenticationRevocationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $sessionVersion = $request->session()->get(
            StaffAuthenticationRevocationService::SESSION_VERSION_KEY
        );

        if ($user?->is_active
            && $sessionVersion === null
            && (int) $user->authentication_version === 0) {
            $sessionVersion = 0;
            $request->session()->put(
                StaffAuthenticationRevocationService::SESSION_VERSION_KEY,
                $sessionVersion,
            );
        }

        if ($user?->is_active
            && is_int($sessionVersion)
            && hash_equals((string) $user->authentication_version, (string) $sessionVersion)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->regenerate(true);

        $message = $user?->is_active
            ? 'Your session is no longer valid. Please sign in again.'
            : 'Your account has been deactivated.';

        return redirect()->route('login')->with('error', $message);
    }
}
