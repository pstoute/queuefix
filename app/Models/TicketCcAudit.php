<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TicketCcAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'ticket_id',
        'message_id',
        'ticket_cc_recipient_id',
        'actor_type',
        'actor_id',
        'event',
        'email',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<TicketCcRecipient, $this> */
    public function ticketRecipient(): BelongsTo
    {
        return $this->belongsTo(TicketCcRecipient::class, 'ticket_cc_recipient_id');
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
