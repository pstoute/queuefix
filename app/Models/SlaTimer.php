<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaTimer extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'sla_policy_id',
        'first_response_due_at',
        'first_responded_at',
        'resolution_due_at',
        'resolved_at',
        'paused_at',
        'total_paused_seconds',
        'first_response_breached',
        'resolution_breached',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'paused_at' => 'datetime',
            'first_response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
        ];
    }

    /**
     * Get the ticket associated with this SLA timer.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the SLA policy associated with this timer.
     */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }
}
