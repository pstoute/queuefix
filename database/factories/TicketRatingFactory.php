<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\TicketRating> */
class TicketRatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'ticket_id' => fn (array $attributes): string => Ticket::factory()->closed()->create([
                'customer_id' => $attributes['customer_id'],
            ])->id,
            'rating' => fake()->numberBetween(1, 5),
            'feedback' => fake()->optional()->sentence(),
            'submitted_at' => now(),
            'staff_notified_at' => null,
        ];
    }
}
