<?php

namespace Database\Factories;

use App\Models\CannedResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CannedResponse>
 */
class CannedResponseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'is_active' => true,
            'visibility' => CannedResponse::VISIBILITY_ALL_AGENTS,
            'created_by' => User::factory(),
        ];
    }
}
