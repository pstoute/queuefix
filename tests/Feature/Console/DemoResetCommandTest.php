<?php

use App\Console\Commands\DemoResetCommand;
use App\Exceptions\AttachmentRejected;
use App\Models\Message;
use App\Services\Attachments\AttachmentOperationLock;
use App\Services\Attachments\AttachmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

function demoResetCommandTester(array &$calls, ?Closure $beforeCall = null): CommandTester
{
    $command = Mockery::mock(DemoResetCommand::class)->makePartial();
    $command->__construct(app(AttachmentOperationLock::class));
    $command->shouldReceive('call')
        ->andReturnUsing(function (string $name, array $arguments = []) use (&$calls, $beforeCall): int {
            $beforeCall?->__invoke($name);
            $calls[] = [$name, $arguments];

            return 0;
        });
    $command->setLaravel(app());

    return new CommandTester($command);
}

beforeEach(function () {
    config([
        'attachments.operation_lock_store' => 'array',
        'attachments.operation_lock_seconds' => 60,
        'attachments.operation_lock_wait_seconds' => 0,
    ]);
});

test('the demo reset removes attachment blobs from the configured disk before resetting the database', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'demo-attachments',
    ]);
    Storage::fake('demo-attachments');
    Storage::disk('demo-attachments')->put('attachments/tickets/123/payload.txt', 'attachment');
    Storage::disk('demo-attachments')->put('attachments/inbound/message-id/payload.txt', 'inbound attachment');
    Storage::disk('demo-attachments')->put('keep/unrelated.txt', 'unrelated');
    config(['attachments.scanning_required' => false]);
    $message = Message::factory()->create();
    $attachmentService = app(AttachmentService::class);
    $calls = [];

    $exitCode = demoResetCommandTester($calls, function (string $name) use ($attachmentService, $message): void {
        if ($name === 'migrate:fresh') {
            Storage::disk('demo-attachments')->assertMissing('attachments/tickets/123/payload.txt');

            expect(fn () => $attachmentService->storeForMessage($message, [[
                'filename' => 'racing.txt',
                'content' => 'concurrent attachment',
            ]]))->toThrow(AttachmentRejected::class, 'temporarily unavailable');
        }
    })->execute([]);

    expect($exitCode)->toBe(DemoResetCommand::SUCCESS)
        ->and($calls[0][0] ?? null)->toBe('migrate:fresh');
    Storage::disk('demo-attachments')->assertMissing('attachments/tickets/123/payload.txt');
    Storage::disk('demo-attachments')->assertMissing('attachments/inbound/message-id/payload.txt');
    expect(Storage::disk('demo-attachments')->allFiles('attachments'))->toBe([]);
    $this->assertDatabaseCount('attachments', 0);
    Storage::disk('demo-attachments')->assertExists('keep/unrelated.txt');
});

test('a real demo reset removes both old attachment metadata and its storage object', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'demo-attachments',
        'attachments.scanning_required' => false,
    ]);
    Storage::fake('demo-attachments');
    $databasePath = tempnam(sys_get_temp_dir(), 'queuefix-demo-reset-test-');
    expect($databasePath)->not->toBeFalse();
    $originalConnection = DB::getDefaultConnection();
    config([
        'database.default' => 'demo-reset-test',
        'database.connections.demo-reset-test' => [
            ...config('database.connections.sqlite'),
            'database' => $databasePath,
        ],
    ]);
    DB::purge('demo-reset-test');
    DB::setDefaultConnection('demo-reset-test');

    try {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $message = Message::factory()->create();
        $attachment = app(AttachmentService::class)->storeForMessage($message, [[
            'filename' => 'old-demo.txt',
            'content' => 'old demo attachment',
        ]])->sole();

        expect($attachment->path)->not->toBeNull();
        Storage::disk('demo-attachments')->assertExists($attachment->path);
        $this->assertDatabaseCount('attachments', 1);

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertDatabaseCount('attachments', 0);
        expect(Storage::disk('demo-attachments')->allFiles('attachments'))->toBe([]);
    } finally {
        DB::disconnect('demo-reset-test');
        DB::setDefaultConnection($originalConnection);
        config(['database.default' => $originalConnection]);
        unlink($databasePath);
    }
});

test('the demo reset aborts before database destruction when attachment cleanup fails', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'failing-attachments',
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('deleteDirectory')
        ->once()
        ->with('attachments')
        ->andReturnFalse();
    Storage::shouldReceive('disk')
        ->once()
        ->with('failing-attachments')
        ->andReturn($disk);
    $calls = [];

    $tester = demoResetCommandTester($calls);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(DemoResetCommand::FAILURE)
        ->and($calls)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('The database was not reset.');
});

test('the demo reset aborts when attachment cleanup throws', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'throwing-attachments',
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('deleteDirectory')
        ->once()
        ->with('attachments')
        ->andThrow(new RuntimeException('storage denied'));
    Storage::shouldReceive('disk')
        ->once()
        ->with('throwing-attachments')
        ->andReturn($disk);
    $calls = [];

    $tester = demoResetCommandTester($calls);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(DemoResetCommand::FAILURE)
        ->and($calls)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('The database was not reset.');
});

test('the demo reset aborts when attachment cleanup leaves a residual object', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'residual-attachments',
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('deleteDirectory')
        ->once()
        ->with('attachments')
        ->andReturnTrue();
    $disk->shouldReceive('allFiles')
        ->once()
        ->with('attachments')
        ->andReturn(['attachments/residual.txt']);
    Storage::shouldReceive('disk')
        ->once()
        ->with('residual-attachments')
        ->andReturn($disk);
    $calls = [];

    $tester = demoResetCommandTester($calls);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(DemoResetCommand::FAILURE)
        ->and($calls)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('The database was not reset.');
});

test('the demo reset aborts when another attachment storage operation owns the lock', function () {
    config([
        'demo.enabled' => true,
        'attachments.disk' => 'demo-attachments',
    ]);
    Storage::fake('demo-attachments');
    Storage::disk('demo-attachments')->put('attachments/tickets/123/payload.txt', 'attachment');
    $lock = Cache::store('array')->lock(AttachmentOperationLock::NAME, 60);
    expect($lock->get())->toBeTrue();
    $calls = [];

    try {
        $tester = demoResetCommandTester($calls);
        $exitCode = $tester->execute([]);
    } finally {
        $lock->release();
    }

    expect($exitCode)->toBe(DemoResetCommand::FAILURE)
        ->and($calls)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('Attachment storage is busy.');
    Storage::disk('demo-attachments')->assertExists('attachments/tickets/123/payload.txt');
});

test('the demo reset remains disabled outside demo mode', function () {
    config([
        'demo.enabled' => false,
        'attachments.disk' => 'demo-attachments',
    ]);
    Storage::fake('demo-attachments');
    Storage::disk('demo-attachments')->put('attachments/tickets/123/payload.txt', 'attachment');
    $calls = [];

    $tester = demoResetCommandTester($calls);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(DemoResetCommand::FAILURE)
        ->and($calls)->toBeEmpty()
        ->and($tester->getDisplay())->toContain('Demo mode is not enabled.');
    Storage::disk('demo-attachments')->assertExists('attachments/tickets/123/payload.txt');
});
