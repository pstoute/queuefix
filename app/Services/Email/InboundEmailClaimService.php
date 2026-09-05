<?php

namespace App\Services\Email;

use App\Models\InboundEmailClaim;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final class InboundEmailClaimService
{
    public function __construct(private InboundEmailPollingPolicy $policy) {}

    public function acquire(string $mailboxId, string $providerMessageId, string $claimToken): bool
    {
        $idempotencyKey = InboundEmailIdentity::key($mailboxId, $providerMessageId);
        $now = now();
        $leaseExpiresAt = $now->copy()->addSeconds($this->policy->claimLeaseSeconds());

        try {
            InboundEmailClaim::create([
                'mailbox_id' => $mailboxId,
                'idempotency_key' => $idempotencyKey,
                'claim_token' => $claimToken,
                'lease_expires_at' => $leaseExpiresAt,
                'retry_not_before' => null,
                'exhausted_at' => null,
                'failure_count' => 0,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // An existing lease may be reclaimed below only after both its
            // lease and any final-failure cooldown have expired.
        }

        $claim = InboundEmailClaim::query()
            ->where('mailbox_id', $mailboxId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $claim
            || $claim->exhausted_at !== null
            || $claim->lease_expires_at->isAfter($now)
            || $claim->retry_not_before?->isAfter($now)) {
            return false;
        }

        return InboundEmailClaim::query()
            ->whereKey($claim->id)
            ->where('claim_token', $claim->claim_token)
            ->whereNull('exhausted_at')
            ->where('lease_expires_at', '<=', $now)
            ->where($this->retryIsDue($now))
            ->update([
                'claim_token' => $claimToken,
                'lease_expires_at' => $leaseExpiresAt,
                'retry_not_before' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    public function renew(string $mailboxId, string $providerMessageId, string $claimToken): bool
    {
        $now = now();

        return InboundEmailClaim::query()
            ->where('mailbox_id', $mailboxId)
            ->where('idempotency_key', InboundEmailIdentity::key($mailboxId, $providerMessageId))
            ->where('claim_token', $claimToken)
            ->whereNull('exhausted_at')
            ->where($this->retryIsDue($now))
            ->update([
                'lease_expires_at' => $now->copy()->addSeconds($this->policy->claimLeaseSeconds()),
                'updated_at' => $now,
            ]) === 1;
    }

    public function release(string $mailboxId, string $providerMessageId, string $claimToken): void
    {
        InboundEmailClaim::query()
            ->where('mailbox_id', $mailboxId)
            ->where('idempotency_key', InboundEmailIdentity::key($mailboxId, $providerMessageId))
            ->where('claim_token', $claimToken)
            ->delete();
    }

    public function deferAfterFinalFailure(string $mailboxId, string $providerMessageId, string $claimToken): void
    {
        $claim = InboundEmailClaim::query()
            ->where('mailbox_id', $mailboxId)
            ->where('idempotency_key', InboundEmailIdentity::key($mailboxId, $providerMessageId))
            ->where('claim_token', $claimToken)
            ->first();

        if (! $claim) {
            return;
        }

        $failureCount = min(65_535, $claim->failure_count + 1);
        $now = now();
        $isExhausted = $failureCount >= $this->policy->maxFailureCount();

        InboundEmailClaim::query()
            ->whereKey($claim->id)
            ->where('claim_token', $claimToken)
            ->update([
                'failure_count' => $failureCount,
                'lease_expires_at' => $now,
                'retry_not_before' => $isExhausted
                    ? null
                    : $now->copy()->addSeconds($this->policy->retryDelaySeconds($failureCount)),
                'exhausted_at' => $isExhausted ? $now : null,
                'updated_at' => $now,
            ]);
    }

    public function retryExhausted(string $mailboxId, string $providerMessageId): bool
    {
        return InboundEmailClaim::query()
            ->where('mailbox_id', $mailboxId)
            ->where('idempotency_key', InboundEmailIdentity::key($mailboxId, $providerMessageId))
            ->whereNotNull('exhausted_at')
            ->delete() === 1;
    }

    private function retryIsDue(\DateTimeInterface $now): callable
    {
        return fn (Builder $query): Builder => $query
            ->whereNull('retry_not_before')
            ->orWhere('retry_not_before', '<=', $now);
    }
}
