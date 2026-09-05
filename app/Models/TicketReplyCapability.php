<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReplyCapability extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'origin_ticket_id',
        'mailbox_id',
        'token_hash',
        'token',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
        'token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function originTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'origin_ticket_id');
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
