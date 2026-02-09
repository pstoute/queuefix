<?php

namespace Database\Factories;

use App\Enums\MailboxType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mailbox>
 */
class MailboxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Support',
            'email' => fake()->unique()->safeEmail(),
            'type' => MailboxType::Imap,
            'credentials' => [
                'username' => fake()->email(),
                'password' => 'secret',
            ],
            'incoming_settings' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => 'ssl',
            ],
            'outgoing_settings' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'department_id' => null,
            'polling_interval' => 2,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function gmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MailboxType::Gmail,
            'credentials' => [
                'access_token' => 'test_access_token',
                'refresh_token' => 'test_refresh_token',
            ],
        ]);
    }
}
