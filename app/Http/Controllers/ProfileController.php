<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Auth\StaffAccountLifecycleService;
use App\Services\Auth\StaffAuthenticationRevocationService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class ProfileController extends Controller
{
    public function __construct(
        private StaffAccountLifecycleService $staffAccounts,
    ) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Profile updates require an authenticated staff account.');
        }

        /** @var array{name: string, email: string} $attributes */
        $attributes = $request->safe()->only(['name', 'email']);
        $changedUser = $this->staffAccounts->updateProfile(
            $user,
            $attributes,
            $request->string('current_password')->toString() ?: null,
        );

        if ($changedUser->wasChanged('email')) {
            $request->session()->regenerate(true);
            $request->session()->put(
                StaffAuthenticationRevocationService::SESSION_VERSION_KEY,
                $changedUser->authentication_version,
            );
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Profile deletion requires an authenticated staff account.');
        }

        Auth::logout();

        $this->staffAccounts->delete($user, $validated['password']);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
