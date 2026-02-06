<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'subject',
        'status',
        'priority',
        'customer_id',
        'assigned_to',
        'mailbox_id',
        'last_activity_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
            if (empty($ticket->last_activity_at)) {
                $ticket->last_activity_at = now();
            }
        });
    }

    /**
     * Generate a unique ticket number.
     */
    protected static function generateTicketNumber(): string
    {
        $lastTicket = static::orderBy('created_at', 'desc')->first();
        $nextNumber = $lastTicket ? (int) str_replace('ST-', '', $lastTicket->ticket_number) + 1 : 1;

        return 'ST-' . $nextNumber;
    }

    /**
     * Get the customer that owns the ticket.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user assigned to the ticket.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the mailbox associated with the ticket.
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * Get the messages for the ticket.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the SLA timer for the ticket.
     */
    public function slaTimer(): HasOne
    {
        return $this->hasOne(SlaTimer::class);
    }

    /**
     * Get the tags associated with the ticket.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_ticket');
    }
}
