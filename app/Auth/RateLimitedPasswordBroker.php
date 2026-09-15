<?php

namespace App\Auth;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\TokenRepositoryInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Timebox;
use LogicException;
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

            if (! $user instanceof User) {
                throw new UnexpectedValueException('Password-reset users must be QueueFix staff accounts.');
            }

            $defaultConnection = (string) config('database.default');
            $userConnection = $user->getConnectionName() ?? $defaultConnection;

            if ($userConnection !== $defaultConnection) {
                throw new LogicException(
                    'Staff password-reset issuance must use the default transactional database connection.'
                );
            }

            return DB::transaction(function () use ($user, $callback): string {
                $lockedUser = $user->newQuery()->lockForUpdate()->find($user->getKey());

                if (! $lockedUser instanceof User) {
                    return static::INVALID_USER;
                }

                if (! $lockedUser->is_active) {
                    $this->tokens->delete($lockedUser);

                    return static::INVALID_USER;
                }

                if ($this->tokens->recentlyCreatedToken($lockedUser)) {
                    return static::RESET_THROTTLED;
                }

                if ($this->rateLimiter->hit(
                    'password-reset:recipient:'.hash('sha256', get_class($lockedUser).':'.$lockedUser->getAuthIdentifier()),
                    self::RECIPIENT_DECAY_SECONDS,
                ) > self::RECIPIENT_MAX_ATTEMPTS) {
                    return static::RESET_THROTTLED;
                }

                $token = $this->tokens->create($lockedUser);

                if ($callback !== null) {
                    return $callback($lockedUser, $token) ?? static::RESET_LINK_SENT;
                }

                $lockedUser->sendPasswordResetNotification($token);
                $this->events?->dispatch(new PasswordResetLinkSent($lockedUser));

                return static::RESET_LINK_SENT;
            });
        }, $this->timeboxDuration);
    }
}
