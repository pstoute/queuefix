<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscalationRule extends Model
{
    use HasUuids;

    public const TRIGGER_NO_FIRST_RESPONSE = 'no_first_response';

    public const TRIGGER_NO_ACTIVITY = 'no_activity';

    public const TRIGGER_SLA_APPROACHING = 'sla_approaching';

    public const TRIGGER_SLA_BREACHED = 'sla_breached';

    public const TRIGGER_STATUS_ENTERED = 'status_entered';

    public const TRIGGER_PRIORITY_CHANGED = 'priority_changed';

    public const ACTION_ASSIGN = 'assign';

    public const ACTION_PRIORITY = 'priority';

    public const ACTION_STATUS = 'status';

    public const ACTION_INTERNAL_NOTE = 'internal_note';

    public const ACTION_ADD_TAG = 'add_tag';

    public const ACTION_REMOVE_TAG = 'remove_tag';

    public const ACTION_NOTIFY = 'notify';

    protected $fillable = [
        'name',
        'trigger',
        'trigger_config',
        'filters',
        'actions',
        'include_closed',
        'include_archived',
        'is_active',
        'created_by',
        'last_previewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'filters' => 'array',
            'actions' => 'array',
            'include_closed' => 'boolean',
            'include_archived' => 'boolean',
            'is_active' => 'boolean',
            'last_previewed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function triggers(): array
    {
        return [
            self::TRIGGER_NO_FIRST_RESPONSE,
            self::TRIGGER_NO_ACTIVITY,
            self::TRIGGER_SLA_APPROACHING,
            self::TRIGGER_SLA_BREACHED,
            self::TRIGGER_STATUS_ENTERED,
            self::TRIGGER_PRIORITY_CHANGED,
        ];
    }

    /** @return list<string> */
    public static function actionTypes(): array
    {
        return [
            self::ACTION_ASSIGN,
            self::ACTION_PRIORITY,
            self::ACTION_STATUS,
            self::ACTION_INTERNAL_NOTE,
            self::ACTION_ADD_TAG,
            self::ACTION_REMOVE_TAG,
            self::ACTION_NOTIFY,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EscalationLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(EscalationLog::class);
    }
}
