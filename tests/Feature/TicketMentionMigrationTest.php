<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('migration assigns normalized unique handles to existing users', function () {
    $migration = require database_path('migrations/2026_09_04_000005_add_user_handles_and_create_ticket_mentions_table.php');
    $migration->down();

    $now = now();
    $users = [
        ['id' => (string) Str::uuid(), 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
        ['id' => (string) Str::uuid(), 'name' => 'Jane Doe', 'email' => 'jane-two@example.com'],
        ['id' => (string) Str::uuid(), 'name' => '---', 'email' => 'fallback@example.com'],
    ];

    foreach ($users as $user) {
        DB::table('users')->insert($user + [
            'password' => 'unused',
            'role' => 'agent',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $migration->up();

    expect(DB::table('users')
        ->whereIn('email', ['jane@example.com', 'jane-two@example.com'])
        ->orderBy('handle')
        ->pluck('handle')
        ->all())->toBe(['jane-doe', 'jane-doe-2'])
        ->and(DB::table('users')->where('email', 'fallback@example.com')->value('handle'))->toBe('user');
});
