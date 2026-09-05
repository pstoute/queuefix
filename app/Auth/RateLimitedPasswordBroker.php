<?php

namespace App\Auth;

use Closure;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Timebox;
use UnexpectedValueException;

class RateLimitedPasswordBroker extends PasswordBroker
{
    private const RECIPIENT_DECAY_SECONDS = 15 * 60;

    private const RECIPIENT_MAX_ATTEMPTS = 3;

    public function __construct(
        TokenRepositoryInterface $tokens,
        UserProvider $users,
        ?Dispatcher $dispatcher,
        ?Timebox $timebox,
        int $timeboxDuration,
        private readonly RateLimiter $rateLimiter,
    ) {
        parent::__construct($tokens, $users, $dispatcher, $timebox, $timeboxDuration);
    }

    /**
     * Send a password reset link while silently limiting the resolved account.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function sendResetLink(#[\SensitiveParameter] array $credentials, ?Closure $callback = null): string
    {
        return $this->timebox->call(function () use ($credentials, $callback): string {
            $user = $this->getUser($credentials);

            if ($user === null) {
                return static::INVALID_USER;
            }

            if ($this->tokens->recentlyCreatedToken($user)) {
                return static::RESET_THROTTLED;
            }

            if (! $user instanceof Authenticatable) {
                throw new UnexpectedValueException('Password-reset users must implement the authenticatable contract.');
            }

            if ($this->rateLimiter->hit(
                'password-reset:recipient:'.hash('sha256', get_class($user).':'.$user->getAuthIdentifier()),
                self::RECIPIENT_DECAY_SECONDS,
            ) > self::RECIPIENT_MAX_ATTEMPTS) {
                return static::RESET_THROTTLED;
            }

            $token = $this->tokens->create($user);

            if ($callback !== null) {
                return $callback($user, $token) ?? static::RESET_LINK_SENT;
            }

            $user->sendPasswordResetNotification($token);
            $this->events?->dispatch(new PasswordResetLinkSent($user));

            return static::RESET_LINK_SENT;
        }, $this->timeboxDuration);
    }
}
