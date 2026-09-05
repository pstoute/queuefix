<?php

namespace App\Console\Commands;

use App\Services\Attachments\AttachmentOperationLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the demo environment to a fresh state';

    public function __construct(
        private readonly AttachmentOperationLock $operationLock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->error('Demo mode is not enabled. Set QUEUEFIX_DEMO_MODE=true in .env.');

            return self::FAILURE;
        }

        $this->info('Resetting demo environment...');

        try {
            $result = $this->operationLock->run(fn (): int => $this->resetData());
        } catch (LockTimeoutException) {
            $this->error('Attachment storage is busy. The database was not reset.');

            return self::FAILURE;
        }

        if ($result !== self::SUCCESS) {
            return $result;
        }

        $this->call('cache:clear');

        // Clear sessions
        $sessionPath = storage_path('framework/sessions');
        if (is_dir($sessionPath)) {
            $files = glob($sessionPath.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $this->info('Demo environment reset complete.');

        return self::SUCCESS;
    }

    private function resetData(): int
    {
        if (! $this->clearAttachments()) {
            return self::FAILURE;
        }

        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('The demo database could not be reset.');

            return self::FAILURE;
        }

        if ($this->call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]) !== self::SUCCESS) {
            $this->error('The demo database could not be seeded.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function clearAttachments(): bool
    {
        try {
            $disk = Storage::disk((string) config('attachments.disk'));

            if (! $disk->deleteDirectory('attachments') || $disk->allFiles('attachments') !== []) {
                $this->error('Unable to verify that demo attachments were removed. The database was not reset.');

                return false;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to remove demo attachments. The database was not reset.');

            return false;
        }

        return true;
    }
}
