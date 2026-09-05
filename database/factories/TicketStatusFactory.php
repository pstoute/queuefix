<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\TicketStatus> */
class TicketStatusFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'color' => fake()->hexColor(),
            'icon' => null,
            'sort_order' => fake()->numberBetween(60, 1000),
            'is_default' => false,
            'is_closed' => false,
            'is_system' => false,
            'is_customer_visible' => true,
            'pauses_sla' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['is_closed' => true]);
    }

    public function internal(): static
    {
        return $this->state(fn (): array => ['is_customer_visible' => false]);
    }
}
