<?php

use App\Contracts\MailboxConnector;
use App\Exceptions\MailboxFetchException;
use App\Jobs\FetchEmailsJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MailboxConnectorFactory;
use App\Services\MailboxFetchDispatcher;
use App\Services\MailboxFetchStateService;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Carbon::setTestNow('2026-09-04 12:00:00');
    $this->mailbox = Mailbox::factory()->create();
    $this->factoryFor = function (MailboxConnector $connector): MailboxConnectorFactory {
        $factory = Mockery::mock(MailboxConnectorFactory::class);
        $factory->shouldReceive('resolve')->andReturn($connector);

        return $factory;
    };
});

afterEach(fn () => Carbon::setTestNow());

test('a successful fetch records durable health and clears recovery state', function () {
    Queue::fake();
    $this->mailbox->update([
        'consecutive_fetch_failures' => 3,
        'last_fetch_error_category' => 'transient',
        'last_fetch_error_code' => 'old_error',
        'last_fetch_error_message' => 'Old safe error.',
    ]);
    $connector = Mockery::mock(MailboxConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmails')->once()->andReturn([['message_id' => 'provider-message-1']]);
    $connector->shouldReceive('providerCursor')->once()->andReturn('history-42');

    (new FetchEmailsJob($this->mailbox->id))->handle(
        ($this->factoryFor)($connector),
        app(MailboxFetchStateService::class),
    );

    $mailbox = $this->mailbox->fresh();
    expect($mailbox->last_fetch_attempted_at?->equalTo(now()))->toBeTrue()
        ->and($mailbox->last_fetch_succeeded_at?->equalTo(now()))->toBeTrue()
        ->and($mailbox->provider_cursor)->toBe('history-42')
        ->and($mailbox->consecutive_fetch_failures)->toBe(0)
        ->and($mailbox->last_fetch_error_code)->toBeNull()
        ->and($mailbox->next_fetch_at?->equalTo(now()->addMinutes($mailbox->polling_interval)))->toBeTrue()
        ->and($mailbox->pending_inbound_count)->toBe(1)
        ->and($mailbox->ingestionHealthStatus())->toBe('healthy');
    Queue::assertPushed(ProcessInboundEmailJob::class, 1);
});

test('authentication failures persist a safe recovery state', function () {
    $failure = new MailboxFetchException(
        MailboxFetchException::CATEGORY_AUTHENTICATION,
        'imap_connection_authentication_failed',
        'Authentication must be renewed before this mailbox can be accessed.',
    );
    $connector = Mockery::mock(MailboxConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnFalse();
    $connector->shouldReceive('lastFailure')->once()->andReturn($failure);

    (new FetchEmailsJob($this->mailbox->id))->handle(
        ($this->factoryFor)($connector),
        app(MailboxFetchStateService::class),
    );

    $mailbox = $this->mailbox->fresh();
    expect($mailbox->consecutive_fetch_failures)->toBe(1)
        ->and($mailbox->last_fetch_succeeded_at)->toBeNull()
        ->and($mailbox->last_fetch_error_category)->toBe(MailboxFetchException::CATEGORY_AUTHENTICATION)
        ->and($mailbox->next_fetch_at?->equalTo(now()->addHour()))->toBeTrue()
        ->and($mailbox->ingestionHealthStatus())->toBe('authentication_required');
});

test('transient failures back off exponentially and a later success recovers', function () {
    $failure = new MailboxFetchException(
        MailboxFetchException::CATEGORY_TRANSIENT,
        'gmail_fetch_temporarily_unavailable',
        'The mailbox provider is temporarily unavailable.',
    );
    $failingConnector = Mockery::mock(MailboxConnector::class);
    $failingConnector->shouldReceive('connect')->twice()->andReturnTrue();
    $failingConnector->shouldReceive('fetchNewEmails')->twice()->andThrow($failure);
    $job = new FetchEmailsJob($this->mailbox->id);

    $job->handle(($this->factoryFor)($failingConnector), app(MailboxFetchStateService::class));
    expect($this->mailbox->fresh()->next_fetch_at?->equalTo(now()->addMinutes(5)))->toBeTrue();

    $job->handle(($this->factoryFor)($failingConnector), app(MailboxFetchStateService::class));
    expect($this->mailbox->fresh()->consecutive_fetch_failures)->toBe(2)
        ->and($this->mailbox->fresh()->next_fetch_at?->equalTo(now()->addMinutes(10)))->toBeTrue();

    $healthyConnector = Mockery::mock(MailboxConnector::class);
    $healthyConnector->shouldReceive('connect')->once()->andReturnTrue();
    $healthyConnector->shouldReceive('fetchNewEmails')->once()->andReturn([]);
    $healthyConnector->shouldReceive('providerCursor')->once()->andReturnNull();
    $job->handle(($this->factoryFor)($healthyConnector), app(MailboxFetchStateService::class));

    expect($this->mailbox->fresh()->consecutive_fetch_failures)->toBe(0)
        ->and($this->mailbox->fresh()->last_fetch_error_message)->toBeNull()
        ->and($this->mailbox->fresh()->last_fetch_succeeded_at)->not->toBeNull();
});

test('manual fetch is admin only and duplicate queued imports are rejected', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $agent = User::factory()->create();

    actingAs($agent);
    post(route('settings.mailboxes.fetch', $this->mailbox))->assertForbidden();

    actingAs($admin);
    post(route('settings.mailboxes.fetch', $this->mailbox))
        ->assertRedirect()
        ->assertSessionHas('success');
    post(route('settings.mailboxes.fetch', $this->mailbox))
        ->assertRedirect()
        ->assertSessionHas('error');
    post(route('settings.mailboxes.test', $this->mailbox))
        ->assertRedirect()
        ->assertSessionHas('error', 'Wait for the queued mailbox fetch to finish before testing the connection.');

    Queue::assertPushed(FetchEmailsJob::class, 1);
    expect($this->mailbox->fresh()->ingestionQueueStatus())->toBe('queued')
        ->and((new FetchEmailsJob($this->mailbox->id))->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

test('scheduled dispatch respects retry time while manual recovery may bypass it', function () {
    Queue::fake();
    $this->mailbox->update(['next_fetch_at' => now()->addMinutes(30)]);
    $dispatcher = app(MailboxFetchDispatcher::class);

    expect($dispatcher->dispatch($this->mailbox))->toBeFalse()
        ->and($dispatcher->dispatch($this->mailbox, manual: true))->toBeTrue();
    Queue::assertPushed(FetchEmailsJob::class, 1);
});

test('connection tests and health payloads never render credentials or provider text', function () {
    $secret = 'access-token-should-never-appear';
    $this->mailbox->update([
        'credentials' => ['access_token' => $secret],
        'last_fetch_error_code' => 'gmail_fetch_failed',
        'last_fetch_error_message' => 'The mailbox provider rejected the request.',
        'consecutive_fetch_failures' => 1,
    ]);
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    expect($this->mailbox->fresh()->toArray())->not->toHaveKey('credentials');

    get(route('settings.mailboxes.index'))
        ->assertOk()
        ->assertDontSee($secret)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mailboxes.0.last_fetch_error_code', 'gmail_fetch_failed')
            ->missing('mailboxes.0.credentials'));

    $connector = Mockery::mock(MailboxConnector::class);
    $connector->shouldReceive('testConnection')->once()->andReturn([
        'success' => false,
        'message' => "Raw provider response with {$secret}",
    ]);
    app()->instance(MailboxConnectorFactory::class, ($this->factoryFor)($connector));

    post(route('settings.mailboxes.test', $this->mailbox))
        ->assertRedirect()
        ->assertSessionHas('error', 'Connection test failed. Review the mailbox health state and credentials.')
        ->assertDontSee($secret);
});

test('raw exception and email text are replaced with safe fetch and processing failures', function () {
    $secret = 'private-message-and-token';
    Log::spy();
    $connector = Mockery::mock(MailboxConnector::class);
    $connector->shouldReceive('connect')->once()->andReturnTrue();
    $connector->shouldReceive('fetchNewEmails')->once()->andThrow(new RuntimeException("timeout {$secret}"));

    (new FetchEmailsJob($this->mailbox->id))->handle(
        ($this->factoryFor)($connector),
        app(MailboxFetchStateService::class),
    );

    $mailbox = $this->mailbox->fresh();
    expect($mailbox->last_fetch_error_message)->toBe('The mailbox provider is temporarily unavailable.')
        ->and(json_encode($mailbox->only(['last_fetch_error_code', 'last_fetch_error_message'])))->not->toContain($secret);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Mailbox fetch failed'
        && ! str_contains(json_encode($context) ?: '', $secret));

    $mailbox->update(['pending_inbound_count' => 1]);
    $processor = Mockery::mock(EmailProcessorService::class);
    $processor->shouldReceive('processInboundEmail')->once()->andThrow(new RuntimeException($secret));
    $processingJob = new ProcessInboundEmailJob(['subject' => $secret], $mailbox->id);

    try {
        $processingJob->handle($processor, app(MailboxFetchStateService::class));
        $this->fail('Expected the processing job to fail.');
    } catch (MailboxFetchException $failure) {
        expect($failure->getMessage())->not->toContain($secret);
        $processingJob->failed($failure);
    }

    expect($mailbox->fresh()->pending_inbound_count)->toBe(0)
        ->and($mailbox->fresh()->consecutive_processing_failures)->toBe(1)
        ->and($mailbox->fresh()->last_processing_error_message)->not->toContain($secret)
        ->and($mailbox->fresh()->ingestionHealthStatus())->toBe('fetch_failing');

    $mailbox->update([
        'consecutive_fetch_failures' => 0,
        'last_fetch_error_category' => null,
        'last_fetch_error_code' => null,
        'last_fetch_error_message' => null,
    ]);
    expect($mailbox->fresh()->ingestionHealthStatus())->toBe('processing_failing');
});
