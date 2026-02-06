<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'sender_type',
        'sender_id',
        'type',
        'body_text',
        'body_html',
        'message_id',
        'in_reply_to',
        'references',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Get the ticket that owns the message.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the sender (User or Customer) that created the message.
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
