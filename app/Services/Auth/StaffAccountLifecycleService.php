<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;

class StaffAccountLifecycleService
{
    public function __construct(
        private StaffAuthenticationRevocationService $authenticationRevoker,
    ) {}

    /**
     * @param  array{name: string, email: string, role: UserRole, password: string, is_active: bool}  $attributes
     */
    public function create(array $attributes): User
    {
        $this->ensureDefaultConnection(new User);

        return DB::transaction(function () use ($attributes): User {
            $this->deletePasswordResetTokens($attributes['email']);

            return User::query()->create($attributes);
        });
    }

    /**
     * @param  array{name?: string, role?: UserRole, is_active?: bool}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $this->ensureDefaultConnection($user);

        return DB::transaction(function () use ($user, $attributes): User {
            $lockedUser = $this->lock($user);

            $statusChanged = array_key_exists('is_active', $attributes)
                && $attributes['is_active'] !== $lockedUser->is_active;

            if ($statusChanged && $attributes['is_active']) {
                $this->authenticationRevoker->revokeAll($lockedUser);
            }

            $lockedUser->fill($attributes)->save();

            if ($statusChanged && ! $attributes['is_active']) {
                $this->authenticationRevoker->revokeAll($lockedUser);
            }

            return $lockedUser;
        });
    }

    /**
     * @param  array{name: string, email: string}  $attributes
     */
    public function updateProfile(
        User $user,
        array $attributes,
        #[\SensitiveParameter] ?string $currentPassword,
    ): User {
        $this->ensureDefaultConnection($user);

        return DB::transaction(function () use ($user, $attributes, $currentPassword): User {
            $lockedUser = $this->lock($user);
            $emailChanged = $attributes['email'] !== $lockedUser->email;

            if ($emailChanged) {
                if (! is_string($currentPassword)
                    || ! Hash::check($currentPassword, $lockedUser->password)) {
                    throw ValidationException::withMessages([
                        'current_password' => 'The password is incorrect.',
                    ]);
                }

                $this->authenticationRevoker->revokeAll($lockedUser);
                $this->deletePasswordResetTokens($attributes['email']);
                $lockedUser->email_verified_at = null;
            }

            $lockedUser->fill($attributes)->save();

            return $lockedUser;
        });
    }

    public function delete(User $user, #[\SensitiveParameter] string $currentPassword): void
    {
        $this->ensureDefaultConnection($user);

        DB::transaction(function () use ($user, $currentPassword): void {
            $lockedUser = $this->lock($user);

            if (! Hash::check($currentPassword, $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The password is incorrect.',
                ]);
            }

            $this->authenticationRevoker->revokeAll($lockedUser);
            $lockedUser->delete();
        });
    }

    /**
     * @param  array{name: string, email: string, email_verified_at: mixed, password: string, role: UserRole, is_active: bool, remember_token: null}  $attributes
     */
    public function bootstrapAdministrator(?User $legacyAdmin, array $attributes): User
    {
        $this->ensureDefaultConnection($legacyAdmin ?? new User);

        return DB::transaction(function () use ($legacyAdmin, $attributes): User {
            if ($legacyAdmin instanceof User) {
                $lockedAdmin = $this->lock($legacyAdmin);

                $this->authenticationRevoker->revokeAll($lockedAdmin);
                $this->deletePasswordResetTokens($attributes['email']);
                $lockedAdmin->forceFill($attributes)->save();

                return $lockedAdmin;
            }

            $this->deletePasswordResetTokens($attributes['email']);

            return User::query()->forceCreate($attributes);
        });
    }

    private function lock(User $user): User
    {
        $lockedUser = $user->newQuery()->lockForUpdate()->find($user->getKey());

        if (! $lockedUser instanceof User) {
            throw new LogicException('The managed staff account no longer exists.');
        }

        return $lockedUser;
    }

    private function deletePasswordResetTokens(string ...$emails): void
    {
        $brokerName = (string) config('auth.defaults.passwords');
        $brokerConfig = config("auth.passwords.{$brokerName}");
        $defaultConnection = (string) config('database.default');

        if (! is_array($brokerConfig)
            || ($brokerConfig['driver'] ?? 'database') !== 'database'
            || ! is_string($brokerConfig['table'] ?? null)
            || $brokerConfig['table'] === ''
            || (string) ($brokerConfig['connection'] ?? $defaultConnection) !== $defaultConnection) {
            throw new LogicException(
                'Staff identity lifecycle state requires the default database token repository.'
            );
        }

        DB::table($brokerConfig['table'])
            ->whereIn('email', array_values(array_unique($emails)))
            ->delete();
    }

    private function ensureDefaultConnection(User $user): void
    {
        $defaultConnection = (string) config('database.default');
        $userConnection = $user->getConnectionName() ?? $defaultConnection;

        if ($userConnection !== $defaultConnection) {
            throw new LogicException(
                'Staff account lifecycle state must use the default transactional database connection.'
            );
        }
    }
}
