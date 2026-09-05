<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TicketSplitEvent extends Model
{
    use HasUuids;

    public const SOURCE_SPLIT = 'source_split';

    public const NEW_TICKET_CREATED = 'new_ticket_created';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'counterpart_ticket_id',
        'actor_id',
        'event_type',
        'message_count',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ticket split history is immutable.'));
        static::deleting(fn () => throw new LogicException('Ticket split history is immutable.'));
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
