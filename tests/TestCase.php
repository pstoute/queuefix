<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\StaffAuthenticationRevocationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        $resolvedGuard = $guard ?? config('auth.defaults.guard');

        if ($resolvedGuard === 'web' && $user instanceof User) {
            session()->put(
                StaffAuthenticationRevocationService::SESSION_VERSION_KEY,
                $user->authentication_version,
            );
        }

        return $this;
    }
}
