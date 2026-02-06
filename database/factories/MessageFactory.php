<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'sender_type' => Customer::class,
            'sender_id' => Customer::factory(),
            'type' => MessageType::Reply,
            'body_text' => fake()->paragraph(),
            'body_html' => '<p>' . fake()->paragraph() . '</p>',
            'message_id' => '<' . fake()->uuid() . '@example.com>',
            'in_reply_to' => null,
            'references' => null,
        ];
    }

    public function fromAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_type' => User::class,
            'sender_id' => User::factory(),
        ]);
    }

    public function internalNote(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MessageType::InternalNote,
            'sender_type' => User::class,
            'sender_id' => User::factory(),
        ]);
    }
}
