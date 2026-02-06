<?php

namespace Database\Factories;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlaTimer>
 */
class SlaTimerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'sla_policy_id' => SlaPolicy::factory(),
            'first_response_due_at' => now()->addHours(4),
            'first_responded_at' => null,
            'resolution_due_at' => now()->addHours(24),
            'resolved_at' => null,
            'paused_at' => null,
            'total_paused_seconds' => 0,
            'first_response_breached' => false,
            'resolution_breached' => false,
        ];
    }

    public function breachedFirstResponse(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_response_due_at' => now()->subHours(1),
            'first_response_breached' => true,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'paused_at' => now(),
        ]);
    }
}
