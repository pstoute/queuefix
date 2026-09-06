<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class StaffAuthenticationRevocationService
{
    public const SESSION_VERSION_KEY = 'auth.staff_authentication_version';

    public function __construct(
        private MagicLinkService $magicLinks,
    ) {}

    public function revokeAll(User $user): void
    {
        $defaultConnection = (string) config('database.default');
        $brokerName = (string) config('auth.defaults.passwords');
        $brokerConfig = config("auth.passwords.{$brokerName}");

        if (! is_array($brokerConfig)
            || ($brokerConfig['driver'] ?? 'database') !== 'database'
            || ! is_string($brokerConfig['table'] ?? null)
            || $brokerConfig['table'] === '') {
            throw new LogicException('Staff authentication revocation requires the database token repository.');
        }

        $userConnection = $user->getConnectionName() ?? $defaultConnection;
        $sessionConnection = (string) (config('session.connection') ?? $defaultConnection);
        $passwordResetConnection = (string) ($brokerConfig['connection'] ?? $defaultConnection);

        $this->ensureSharedConnection($userConnection, $defaultConnection, 'magic link');
        $this->ensureSharedConnection($userConnection, $sessionConnection, 'session');
        $this->ensureSharedConnection($userConnection, $passwordResetConnection, 'password reset');

        $user->increment('authentication_version');

        DB::connection($sessionConnection)
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();

        $this->magicLinks->revokeStaff($user);

        DB::connection($passwordResetConnection)
            ->table($brokerConfig['table'])
            ->where('email', $user->getEmailForPasswordReset())
            ->delete();
    }

    private function ensureSharedConnection(
        string $userConnection,
        string $relatedConnection,
        string $relatedState,
    ): void {
        if ($userConnection !== $relatedConnection) {
            throw new LogicException(
                "Staff {$relatedState} state must use the same database connection as users."
            );
        }
    }
}
