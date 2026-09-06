<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\StaffPasswordResetService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class NewPasswordController extends Controller
{
    public function __construct(
        private StaffPasswordResetService $passwords,
    ) {}

    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $result = $this->passwords->reset($request->only(
            'email', 'password', 'password_confirmation', 'token'
        ));

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($result['status'] == Password::PASSWORD_RESET) {
            if (! $result['user'] instanceof User) {
                throw new LogicException('Password reset completed without resolving a staff account.');
            }

            event(new PasswordReset($result['user']));

            return redirect()->route('login')->with('status', __($result['status']));
        }

        throw ValidationException::withMessages([
            'email' => [trans($result['status'])],
        ]);
    }
}
