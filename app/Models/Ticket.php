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
        'ticket_status_id',
        'priority',
        'customer_id',
        'assigned_to',
        'mailbox_id',
        'department_id',
        'last_activity_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => \App\Enums\TicketPriority::class,
            'last_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
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
            if (empty($ticket->ticket_status_id)) {
                $ticket->ticket_status_id = TicketStatus::defaultStatus()->id;
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

        return $prefix.'-'.$nextNumber;
    }

    /**
     * Get the customer that owns the ticket.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the configurable workflow status assigned to the ticket.
     *
     * @return BelongsTo<TicketStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'ticket_status_id');
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the messages for the ticket.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<SlaTimer, $this> */
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

    /**
     * Get the agents who explicitly watch this ticket.
     *
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')->withTimestamps();
    }

    /** @return HasMany<TicketReadState, $this> */
    public function readStates(): HasMany
    {
        return $this->hasMany(TicketReadState::class);
    }
}
