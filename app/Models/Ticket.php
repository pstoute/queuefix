<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
            'status' => \App\Enums\TicketStatus::class,
            'priority' => \App\Enums\TicketPriority::class,
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
     * Generate a unique ticket number using an atomic counter.
     */
    protected static function generateTicketNumber(): string
    {
        $prefix = Setting::get('ticket_prefix', 'QF');

        $nextNumber = DB::transaction(function () {
            $counter = Setting::where('key', 'ticket_counter')->lockForUpdate()->first();

            if ($counter) {
                $next = (int) $counter->value + 1;
                $counter->update(['value' => (string) $next]);

                return $next;
            }

            // Fallback: if ticket_counter doesn't exist, derive from existing tickets
            $maxNumber = 0;
            static::pluck('ticket_number')->each(function ($number) use (&$maxNumber) {
                if (preg_match('/-(\d+)$/', $number, $matches)) {
                    $maxNumber = max($maxNumber, (int) $matches[1]);
                }
            });
            $next = $maxNumber + 1;
            Setting::create(['key' => 'ticket_counter', 'value' => (string) $next, 'group' => 'system']);

            return $next;
        });

        return $prefix . '-' . $nextNumber;
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
