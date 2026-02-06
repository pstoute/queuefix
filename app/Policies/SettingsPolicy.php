<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class SettingsPolicy
{
    public function manageSettings(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function manageUsers(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function manageMailboxes(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
