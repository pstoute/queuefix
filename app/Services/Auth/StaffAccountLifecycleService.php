<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class StaffAccountLifecycleService
{
    public function __construct(
        private StaffAuthenticationRevocationService $authenticationRevoker,
    ) {}

    /**
     * @param  array{name?: string, role?: UserRole, is_active?: bool}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $defaultConnection = (string) config('database.default');
        $userConnection = $user->getConnectionName() ?? $defaultConnection;

        if ($userConnection !== $defaultConnection) {
            throw new LogicException(
                'Staff account lifecycle state must use the default transactional database connection.'
            );
        }

        return DB::transaction(function () use ($user, $attributes): User {
            $lockedUser = $user->newQuery()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser instanceof User) {
                throw new LogicException('The managed staff account no longer exists.');
            }

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
}
