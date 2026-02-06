<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'priority',
        'first_response_hours',
        'resolution_hours',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_response_hours' => 'decimal:2',
            'resolution_hours' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the SLA timers associated with this policy.
     */
    public function slaTimers(): HasMany
    {
        return $this->hasMany(SlaTimer::class);
    }
}
