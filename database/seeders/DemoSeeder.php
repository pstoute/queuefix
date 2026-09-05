<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use LogicException;

class DemoSeeder extends Seeder
{
    use SeedsDemoData;

    public function run(): void
    {
        if (config('demo.enabled') !== true) {
            throw new LogicException('Demo seeding requires QUEUEFIX_DEMO_MODE=true.');
        }

        if (User::query()->exists()) {
            throw new LogicException('Demo data can only be seeded into an empty installation.');
        }

        $this->seedDemoData();
    }
}
