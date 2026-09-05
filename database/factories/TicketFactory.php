<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(),
            'ticket_status_id' => fn (): string => TicketStatus::defaultStatus()->id,
            'priority' => TicketPriority::Normal,
            'customer_id' => Customer::factory(),
            'assigned_to' => null,
            'mailbox_id' => null,
            'last_activity_at' => now(),
        ];
    }

    public function withAssignee(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => User::factory(),
        ]);
    }

    public function withMailbox(): static
    {
        return $this->state(fn (array $attributes) => [
            'mailbox_id' => Mailbox::factory(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_status_id' => fn (): string => TicketStatus::query()
                ->where('pauses_sla', true)
                ->ordered()
                ->valueOrFail('id'),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_status_id' => fn (): string => TicketStatus::query()
                ->where('is_closed', true)
                ->ordered()
                ->valueOrFail('id'),
            'resolved_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_status_id' => fn (): string => TicketStatus::systemClosedStatus()->id,
            'resolved_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => TicketPriority::High,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => TicketPriority::Urgent,
        ]);
    }
}
