<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TicketMergeEvent extends Model
{
    use HasUuids;

    public const SOURCE_MERGED = 'source_merged';

    public const TARGET_RECEIVED = 'target_received';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'counterpart_ticket_id',
        'actor_id',
        'event_type',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ticket merge history is immutable.'));
        static::deleting(fn () => throw new LogicException('Ticket merge history is immutable.'));
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function counterpartTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'counterpart_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
