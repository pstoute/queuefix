<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the demo environment to a fresh state';

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->error('Demo mode is not enabled. Set QUEUEFIX_DEMO_MODE=true in .env.');

            return self::FAILURE;
        }

        $this->info('Resetting demo environment...');

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]);

        $this->call('cache:clear');

        // Clear sessions
        $sessionPath = storage_path('framework/sessions');
        if (is_dir($sessionPath)) {
            $files = glob($sessionPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Clear uploaded attachments
        $attachmentsPath = storage_path('app/attachments');
        if (is_dir($attachmentsPath)) {
            File::deleteDirectory($attachmentsPath, true);
        }

        $this->info('Demo environment reset complete.');

        return self::SUCCESS;
    }
}
