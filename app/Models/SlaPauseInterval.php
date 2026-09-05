<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaPauseInterval extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'sla_timer_id',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<SlaTimer, $this> */
    public function slaTimer(): BelongsTo
    {
        return $this->belongsTo(SlaTimer::class);
    }
}
