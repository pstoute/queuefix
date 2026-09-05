<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', 'Authentication failed. Please try again.');
        }

        $user = User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            return redirect()->route('login')
                ->with('error', 'Your account is not authorized. Please contact an administrator.');
        }

        if (! $user->is_active) {
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        $user->update([
            'avatar' => $socialUser->getAvatar(),
        ]);

        Auth::login($user, true);

        return redirect()->intended(route('agent.tickets.index'));
    }

    private function validateProvider(string $provider): void
    {
        if (! in_array($provider, ['google', 'microsoft'])) {
            abort(404);
        }
    }
}
