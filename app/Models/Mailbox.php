<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailbox extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'type',
        'credentials',
        'incoming_settings',
        'outgoing_settings',
        'department_id',
        'polling_interval',
        'is_active',
        'last_checked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'incoming_settings' => 'json',
            'outgoing_settings' => 'json',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Get the tickets associated with this mailbox.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailboxAlias::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
