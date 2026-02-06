<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'appName' => config('app.name', 'QueueFix'),
            'demo' => config('demo.enabled') ? [
                'enabled' => true,
                'githubUrl' => config('demo.github_url'),
                'resetInterval' => config('demo.reset_interval'),
                'credentials' => [
                    'admin' => ['email' => 'admin@example.com', 'password' => 'password'],
                    'agent' => ['email' => 'sarah@example.com', 'password' => 'password'],
                ],
            ] : null,
        ];
    }
}
