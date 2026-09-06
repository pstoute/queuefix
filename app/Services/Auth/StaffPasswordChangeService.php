<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class StaffPasswordChangeService
{
    public function __construct(
        private StaffAuthenticationRevocationService $authenticationRevoker,
    ) {}

    /**
     * @throws ValidationException
     */
    public function change(
        User $user,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): User {
        $defaultConnection = (string) config('database.default');
        $brokerName = (string) config('auth.defaults.passwords');
        $brokerConfig = config("auth.passwords.{$brokerName}");

        if (! is_array($brokerConfig) || ($brokerConfig['driver'] ?? 'database') !== 'database') {
            throw new LogicException('Staff password changes require the database token repository.');
        }

        $connections = [
            $user->getConnectionName() ?? $defaultConnection,
            (string) (config('session.connection') ?? $defaultConnection),
            (string) ($brokerConfig['connection'] ?? $defaultConnection),
        ];

        foreach ($connections as $connection) {
            if ($connection !== $defaultConnection) {
                throw new LogicException(
                    'Staff password change state must use the default transactional database connection.'
                );
            }
        }

        return DB::transaction(function () use ($user, $currentPassword, $newPassword): User {
            $lockedUser = $user->newQuery()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser instanceof User) {
                throw new LogicException('The authenticated staff account no longer exists.');
            }

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => [trans('auth.password')],
                ]);
            }

            $lockedUser->forceFill([
                'password' => Hash::make($newPassword),
                'remember_token' => Str::random(60),
            ])->save();

            $this->authenticationRevoker->revokeAll($lockedUser);

            return $lockedUser;
        });
    }
}
