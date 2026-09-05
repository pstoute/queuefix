<?php

use App\Models\InboundEmailClaim;
use App\Models\Mailbox;
use App\Services\Email\InboundEmailClaimService;
use Illuminate\Support\Str;

test('an operator can explicitly recover an exhausted inbound message', function () {
    config(['inbound.max_failure_count' => 1]);
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'gmail:manual-recovery';
    $claimToken = (string) Str::uuid();
    $claimService = app(InboundEmailClaimService::class);
    expect($claimService->acquire($mailbox->id, $providerMessageId, $claimToken))->toBeTrue();
    $claimService->deferAfterFinalFailure($mailbox->id, $providerMessageId, $claimToken);

    $this->artisan('queuefix:retry-inbound-email', [
        'mailbox' => $mailbox->email,
        'provider_message_id' => $providerMessageId,
    ])->expectsOutput('The exhausted inbound message can be polled again.')
        ->assertSuccessful();

    expect(InboundEmailClaim::query()->count())->toBe(0)
        ->and($claimService->acquire($mailbox->id, $providerMessageId, (string) Str::uuid()))->toBeTrue();
});

test('manual recovery refuses an active claim', function () {
    $mailbox = Mailbox::factory()->create();
    $providerMessageId = 'gmail:still-active';
    expect(app(InboundEmailClaimService::class)->acquire(
        $mailbox->id,
        $providerMessageId,
        (string) Str::uuid(),
    ))->toBeTrue();

    $this->artisan('queuefix:retry-inbound-email', [
        'mailbox' => $mailbox->id,
        'provider_message_id' => $providerMessageId,
    ])->expectsOutput('No exhausted inbound message matched that mailbox and provider identity.')
        ->assertFailed();

    expect(InboundEmailClaim::query()->count())->toBe(1);
});
