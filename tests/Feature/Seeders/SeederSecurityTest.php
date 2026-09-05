<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

test('the default seeder does not create accounts or demo records', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(Ticket::query()->count())->toBe(0);
});

test('the demo seeder requires explicit demo mode', function () {
    config(['demo.enabled' => false]);

    $this->seed(DemoSeeder::class);
})->throws(LogicException::class, 'Demo seeding requires QUEUEFIX_DEMO_MODE=true.');

test('the demo seeder refuses an installation with staff users', function () {
    User::factory()->create();
    config(['demo.enabled' => true]);

    $this->seed(DemoSeeder::class);
})->throws(LogicException::class, 'Demo data can only be seeded into an empty installation.');

test('explicit demo seeding creates the documented demo accounts', function () {
    config(['demo.enabled' => true]);

    $this->seed(DemoSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $agent = User::query()->where('email', 'sarah@example.com')->firstOrFail();

    expect($admin->role)->toBe(UserRole::Admin)
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($agent->role)->toBe(UserRole::Agent)
        ->and(Hash::check('password', $agent->password))->toBeTrue();
});
