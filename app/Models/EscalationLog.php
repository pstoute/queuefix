<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscalationLog extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'escalation_rule_id',
        'ticket_id',
        'idempotency_key',
        'trigger_window',
        'trigger_context',
        'status',
        'attempts',
        'actor',
        'started_at',
        'completed_at',
        'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_context' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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

    /** @return HasMany<EscalationActionLog, $this> */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(EscalationActionLog::class)->orderBy('action_order');
    }
}
