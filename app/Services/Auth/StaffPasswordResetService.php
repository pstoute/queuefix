<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;

class StaffPasswordResetService
{
    public function __construct(
        private StaffAuthenticationRevocationService $authenticationRevoker,
    ) {}

    /**
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
     * @return array{status: string, user: User|null}
     */
    public function reset(#[\SensitiveParameter] array $credentials): array
    {
        $brokerName = (string) config('auth.defaults.passwords');
        $brokerConfig = config("auth.passwords.{$brokerName}");

        if (! is_array($brokerConfig) || ($brokerConfig['driver'] ?? 'database') !== 'database') {
            throw new LogicException('Staff password recovery requires the database token repository.');
        }

        $defaultConnection = (string) config('database.default');
        $userConnection = (new User)->getConnectionName() ?? $defaultConnection;
        $tokenConnection = (string) ($brokerConfig['connection'] ?? $defaultConnection);
        $sessionConnection = (string) (config('session.connection') ?? $defaultConnection);

        foreach ([$userConnection, $tokenConnection, $sessionConnection] as $connection) {
            if ($connection !== $defaultConnection) {
                throw new LogicException(
                    'Staff password recovery state must use the default transactional database connection.'
                );
            }
        }

        return DB::transaction(function () use ($credentials, $brokerConfig, $defaultConnection): array {
            $broker = Password::broker();

            if (! $broker instanceof PasswordBroker) {
                throw new LogicException('Staff password recovery requires Laravel\'s database password broker.');
            }

            $user = $broker->getUser($credentials);

            if (! $user instanceof User) {
                return ['status' => Password::INVALID_USER, 'user' => null];
            }

            $actualUserConnection = $user->getConnectionName() ?? $defaultConnection;

            if ($actualUserConnection !== $defaultConnection) {
                throw new LogicException(
                    'Resolved staff accounts must use the default transactional database connection.'
                );
            }

            $tokenTable = (string) $brokerConfig['table'];
            $email = $user->getEmailForPasswordReset();
            $storedHash = DB::table($tokenTable)->where('email', $email)->value('token');

            if (! is_string($storedHash)
                || ! $broker->tokenExists($user, $credentials['token'])
                || DB::table($tokenTable)
                    ->where('email', $email)
                    ->where('token', $storedHash)
                    ->delete() !== 1) {
                return ['status' => Password::INVALID_TOKEN, 'user' => null];
            }

            $user->forceFill([
                'password' => Hash::make($credentials['password']),
                'remember_token' => Str::random(60),
            ])->save();

            $this->authenticationRevoker->revokeAll($user);

            return ['status' => Password::PASSWORD_RESET, 'user' => $user];
        });
    }
}
