<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TicketCcRecipient extends Model
{
    use HasUuids;

    public const VALIDATION_APPROVED = 'approved';

    protected $fillable = [
        'ticket_id',
        'email',
        'display_name',
        'source',
        'validation_state',
        'added_by_type',
        'added_by_id',
        'approved_at',
        'removed_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'removed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return MorphTo<Model, $this> */
    public function addedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<MessageCcRecipient, $this> */
    public function messageRecipients(): HasMany
    {
        return $this->hasMany(MessageCcRecipient::class);
    }

    /** @return HasMany<TicketCcAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(TicketCcAudit::class);
    }
}
