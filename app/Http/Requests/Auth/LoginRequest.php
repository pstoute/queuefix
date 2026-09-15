<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\StaffPasswordLoginRateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(StaffPasswordLoginRateLimiter $rateLimiter): void
    {
        $identity = $this->string('email')->toString();
        $credentials = [
            ...$this->only('email', 'password'),
            'is_active' => true,
        ];
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('Staff password login requires the web session guard.');
        }

        $account = $guard->getProvider()->retrieveByCredentials($credentials);
        $accountIdentifier = $account === null
            ? null
            : get_class($account).':'.$account->getAuthIdentifier();
        $retryAfter = $rateLimiter->reserve($identity, $this->ip(), $accountIdentifier);

        if ($retryAfter !== null) {
            $this->throwRateLimited($retryAfter);
        }

        if (! $guard->attempt($credentials, $this->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $rateLimiter->clear($identity, $this->ip(), $accountIdentifier);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    private function throwRateLimited(int $seconds): never
    {
        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }
}
