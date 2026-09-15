<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\StaffAuthenticationRevocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use LogicException;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'queuefix:bootstrap-admin
                            {--name= : Administrator display name}
                            {--email= : Administrator email address}';

    protected $description = 'Create the first QueueFix administrator';

    public function handle(StaffAuthenticationRevocationService $authenticationRevoker): int
    {
        if (config('demo.enabled')) {
            $this->error('Administrator bootstrap is disabled in demo mode.');

            return self::FAILURE;
        }

        $legacyAdmin = User::query()->where('email', 'admin@example.com')->first();
        $canRotateLegacyAdmin = $legacyAdmin !== null
            && $legacyAdmin->getRawOriginal('role') === UserRole::Admin->value
            && Hash::check('password', $legacyAdmin->password);

        if (User::query()->exists() && ! $canRotateLegacyAdmin) {
            $this->error('Administrator bootstrap is only available on an empty installation or to rotate the legacy default administrator.');

            return self::FAILURE;
        }

        $defaultName = $legacyAdmin === null ? 'Admin' : $legacyAdmin->name;
        $defaultEmail = $legacyAdmin?->email;
        $name = trim((string) ($this->option('name') ?: $this->ask('Administrator name', $defaultName)));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Administrator email', $defaultEmail))));
        $password = (string) $this->secret('Administrator password');
        $passwordConfirmation = (string) $this->secret('Confirm administrator password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($legacyAdmin?->getKey()),
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        DB::transaction(function () use ($authenticationRevoker, $legacyAdmin, $validated): void {
            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'email_verified_at' => now(),
                'password' => $validated['password'],
                'role' => UserRole::Admin,
                'is_active' => true,
                'remember_token' => null,
            ];

            if ($legacyAdmin) {
                $lockedLegacyAdmin = User::query()
                    ->lockForUpdate()
                    ->find($legacyAdmin->getKey());

                if (! $lockedLegacyAdmin instanceof User) {
                    throw new LogicException('The legacy administrator no longer exists.');
                }

                $authenticationRevoker->revokeAll($lockedLegacyAdmin);
                $lockedLegacyAdmin->forceFill($attributes)->save();

                return;
            }

            User::query()->forceCreate($attributes);
        });

        $this->info($legacyAdmin ? 'Legacy administrator credential rotated.' : 'Administrator created.');

        return self::SUCCESS;
    }
}
