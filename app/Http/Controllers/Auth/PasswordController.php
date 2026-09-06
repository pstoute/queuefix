<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\StaffAuthenticationRevocationService;
use App\Services\Auth\StaffPasswordChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use LogicException;

class PasswordController extends Controller
{
    public function __construct(
        private StaffPasswordChangeService $passwords,
    ) {}

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Password changes require an authenticated staff account.');
        }

        $changedUser = $this->passwords->change(
            $user,
            $validated['current_password'],
            $validated['password'],
        );

        $request->session()->regenerate(true);
        $request->session()->put(
            StaffAuthenticationRevocationService::SESSION_VERSION_KEY,
            $changedUser->authentication_version,
        );

        return back();
    }
}
