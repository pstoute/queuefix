<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Mail;
use App\Mail\MagicLinkMail;

class MagicLinkController extends Controller
{
    public function showForm(): Response
    {
        return Inertia::render('Auth/MagicLink');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        if (! $user) {
            return back()->with('status', 'If an account exists with that email, a magic link has been sent.');
        }

        $url = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->addMinutes(15),
            ['user' => $user->id]
        );

        Mail::to($user->email)->send(new MagicLinkMail($url, $user));

        return back()->with('status', 'If an account exists with that email, a magic link has been sent.');
    }

    public function verify(Request $request, User $user): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('login')
                ->with('error', 'This magic link has expired or is invalid.');
        }

        if (! $user->is_active) {
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated.');
        }

        Auth::login($user, true);

        if (! $user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        return redirect()->intended(route('agent.tickets.index'));
    }
}
