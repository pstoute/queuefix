<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EscalationActionLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'escalation_log_id',
        'escalation_rule_id',
        'ticket_id',
        'attempt',
        'action_order',
        'action_type',
        'status',
        'actor',
        'before_context',
        'after_context',
        'error',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'action_order' => 'integer',
            'before_context' => 'array',
            'after_context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Escalation action history is immutable.'));
        static::deleting(fn () => throw new LogicException('Escalation action history is immutable.'));
    }

    /** @return BelongsTo<EscalationLog, $this> */
    public function log(): BelongsTo
    {
        return $this->belongsTo(EscalationLog::class, 'escalation_log_id');
    }

    /** @return BelongsTo<EscalationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(EscalationRule::class, 'escalation_rule_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
