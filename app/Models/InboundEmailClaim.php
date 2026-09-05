<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $lease_expires_at
 * @property Carbon|null $retry_not_before
 * @property Carbon|null $exhausted_at
 * @property int $failure_count
 */
class InboundEmailClaim extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'mailbox_id',
        'idempotency_key',
        'claim_token',
        'lease_expires_at',
        'retry_not_before',
        'exhausted_at',
        'failure_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'retry_not_before' => 'datetime',
            'exhausted_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
