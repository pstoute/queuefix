<?php

use App\Models\InboundEmailClaim;
use App\Models\Mailbox;
use App\Services\Email\InboundEmailClaimService;
use App\Services\Email\InboundEmailPollingPolicy;
use Illuminate\Support\Str;

test('one active provider claim wins and mailbox identities remain isolated', function () {
    $service = app(InboundEmailClaimService::class);
    $firstMailbox = Mailbox::factory()->create();
    $secondMailbox = Mailbox::factory()->create();
    $firstToken = (string) Str::uuid();
    $secondToken = (string) Str::uuid();

    expect($service->acquire($firstMailbox->id, 'gmail:same-provider-id', $firstToken))->toBeTrue()
        ->and($service->acquire($firstMailbox->id, 'gmail:same-provider-id', $secondToken))->toBeFalse()
        ->and($service->acquire($secondMailbox->id, 'gmail:same-provider-id', $secondToken))->toBeTrue()
        ->and(InboundEmailClaim::query()->count())->toBe(2);
});

test('IMAP UID epochs produce independent claims', function () {
    $service = app(InboundEmailClaimService::class);
    $mailbox = Mailbox::factory()->create();

    expect($service->acquire($mailbox->id, 'imap:INBOX:123:456', (string) Str::uuid()))->toBeTrue()
        ->and($service->acquire($mailbox->id, 'imap:INBOX:124:456', (string) Str::uuid()))->toBeTrue()
        ->and(InboundEmailClaim::query()->count())->toBe(2);
});

test('an expired lease is reclaimed with a new token and rejects the stale owner', function () {
    config(['inbound.claim_lease_seconds' => 120]);
    $service = app(InboundEmailClaimService::class);
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'microsoft:lease-recovery';
    $oldToken = (string) Str::uuid();
    $newToken = (string) Str::uuid();

    expect($service->acquire($mailbox->id, $providerMessageId, $oldToken))->toBeTrue();

    $this->travel(121)->seconds();

    expect($service->acquire($mailbox->id, $providerMessageId, $newToken))->toBeTrue()
        ->and($service->renew($mailbox->id, $providerMessageId, $oldToken))->toBeFalse()
        ->and($service->renew($mailbox->id, $providerMessageId, $newToken))->toBeTrue()
        ->and(InboundEmailClaim::query()->sole()->claim_token)->toBe($newToken);
});

test('a lease renewal remains valid when the database reports no changed values', function () {
    $this->freezeTime();

    $service = app(InboundEmailClaimService::class);
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'gmail:no-op-renewal';
    $claimToken = (string) Str::uuid();

    expect($service->acquire($mailbox->id, $providerMessageId, $claimToken))->toBeTrue()
        ->and($service->renew($mailbox->id, $providerMessageId, $claimToken))->toBeTrue()
        ->and(InboundEmailClaim::query()->sole()->claim_token)->toBe($claimToken);
});

test('final failures impose bounded cooldowns and then require explicit recovery', function () {
    config([
        'inbound.claim_lease_seconds' => 120,
        'inbound.retry_base_seconds' => 60,
        'inbound.retry_max_seconds' => 120,
        'inbound.max_failure_count' => 2,
    ]);
    $service = app(InboundEmailClaimService::class);
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'gmail:final-failure';
    $firstToken = (string) Str::uuid();
    $secondToken = (string) Str::uuid();
    $thirdToken = (string) Str::uuid();

    expect($service->acquire($mailbox->id, $providerMessageId, $firstToken))->toBeTrue();
    $service->deferAfterFinalFailure($mailbox->id, $providerMessageId, $firstToken);

    expect($service->acquire($mailbox->id, $providerMessageId, $secondToken))->toBeFalse()
        ->and(InboundEmailClaim::query()->sole()->failure_count)->toBe(1);

    $this->travel(61)->seconds();

    expect($service->acquire($mailbox->id, $providerMessageId, $secondToken))->toBeTrue();
    $service->deferAfterFinalFailure($mailbox->id, $providerMessageId, $secondToken);

    $claim = InboundEmailClaim::query()->sole();
    expect($claim->failure_count)->toBe(2)
        ->and($claim->retry_not_before)->toBeNull()
        ->and($claim->exhausted_at)->not->toBeNull();

    $this->travel(1)->day();
    expect($service->acquire($mailbox->id, $providerMessageId, $thirdToken))->toBeFalse()
        ->and($service->retryExhausted($mailbox->id, $providerMessageId))->toBeTrue()
        ->and($service->acquire($mailbox->id, $providerMessageId, $thirdToken))->toBeTrue()
        ->and(InboundEmailClaim::query()->sole()->failure_count)->toBe(0);
});

test('explicit recovery refuses active and unknown claims', function () {
    $service = app(InboundEmailClaimService::class);
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'gmail:active-claim';

    expect($service->acquire($mailbox->id, $providerMessageId, (string) Str::uuid()))->toBeTrue()
        ->and($service->retryExhausted($mailbox->id, $providerMessageId))->toBeFalse()
        ->and($service->retryExhausted($mailbox->id, 'gmail:unknown'))->toBeFalse()
        ->and(InboundEmailClaim::query()->count())->toBe(1);
});

test('polling policy clamps operator overrides to hard safety bounds', function () {
    config([
        'inbound.poll_batch_size' => PHP_INT_MAX,
        'inbound.claim_lease_seconds' => PHP_INT_MAX,
        'inbound.retry_base_seconds' => PHP_INT_MAX,
        'inbound.retry_max_seconds' => PHP_INT_MAX,
        'inbound.max_failure_count' => PHP_INT_MAX,
    ]);
    $policy = app(InboundEmailPollingPolicy::class);

    expect($policy->batchSize())->toBe(InboundEmailPollingPolicy::MAX_POLL_BATCH_SIZE)
        ->and($policy->scanSize())->toBe(InboundEmailPollingPolicy::MAX_POLL_SCAN_SIZE)
        ->and($policy->claimLeaseSeconds())->toBe(86_400)
        ->and($policy->retryDelaySeconds(65_535))->toBe(86_400)
        ->and($policy->maxFailureCount())->toBe(20);
});
