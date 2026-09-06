<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MagicLinkService
{
    private const CUSTOMER_GUARD = 'customer';

    private const LOGIN_PURPOSE = 'login';

    private const STAFF_GUARD = 'web';

    /**
     * @return array{token: non-empty-string, expires_at: CarbonInterface}|null
     */
    public function issueStaff(User $user): ?array
    {
        return DB::transaction(function () use ($user): ?array {
            $staff = User::query()->lockForUpdate()->find($user->getKey());

            if (! $staff?->is_active) {
                return null;
            }

            return $this->issue(self::STAFF_GUARD, (string) $staff->getKey());
        });
    }

    /**
     * @return array{token: non-empty-string, expires_at: CarbonInterface}
     */
    public function issueCustomer(Customer $customer): array
    {
        return DB::transaction(function () use ($customer): array {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());

            return $this->issue(self::CUSTOMER_GUARD, (string) $lockedCustomer->getKey());
        });
    }

    public function consumeStaff(User $user, #[\SensitiveParameter] string $token): bool
    {
        return DB::transaction(function () use ($user, $token): bool {
            $staff = User::query()->lockForUpdate()->find($user->getKey());

            if (! $staff?->is_active) {
                $this->revoke(self::STAFF_GUARD, (string) $user->getKey());

                return false;
            }

            return $this->consume(self::STAFF_GUARD, (string) $staff->getKey(), $token);
        });
    }

    public function consumeCustomer(Customer $customer, #[\SensitiveParameter] string $token): bool
    {
        return $this->consume(self::CUSTOMER_GUARD, (string) $customer->getKey(), $token);
    }

    public function revokeStaff(User $user): void
    {
        $this->revoke(self::STAFF_GUARD, (string) $user->getKey());
    }

    /**
     * @return array{token: non-empty-string, expires_at: CarbonInterface}
     */
    private function issue(string $guard, string $authenticatableId): array
    {
        $token = bin2hex(random_bytes(32));
        $now = now();
        $expiresAt = $now->copy()->addMinutes(15);

        DB::table('magic_link_tokens')->upsert(
            [[
                'id' => (string) Str::uuid(),
                'guard' => $guard,
                'purpose' => self::LOGIN_PURPOSE,
                'authenticatable_id' => $authenticatableId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['guard', 'purpose', 'authenticatable_id'],
            ['token_hash', 'expires_at', 'consumed_at', 'updated_at'],
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function consume(string $guard, string $authenticatableId, #[\SensitiveParameter] string $token): bool
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1) {
            return false;
        }

        $now = now();

        return DB::table('magic_link_tokens')
            ->where('guard', $guard)
            ->where('purpose', self::LOGIN_PURPOSE)
            ->where('authenticatable_id', $authenticatableId)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update([
                'consumed_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    private function revoke(string $guard, string $authenticatableId): void
    {
        DB::table('magic_link_tokens')
            ->where('guard', $guard)
            ->where('purpose', self::LOGIN_PURPOSE)
            ->where('authenticatable_id', $authenticatableId)
            ->delete();
    }
}
