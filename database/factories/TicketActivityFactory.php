<?php

namespace Database\Factories;

use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\TicketActivity> */
class TicketActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => null,
            'actor_type' => TicketActivityActorType::System,
            'event_type' => TicketActivityType::TicketCreated,
            'before' => null,
            'after' => ['status' => 'open'],
            'summary' => 'Ticket created',
            'correlation_id' => (string) fake()->uuid(),
            'customer_visible' => true,
            'created_at' => now(),
        ];
    }
}
