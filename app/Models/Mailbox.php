<?php

namespace App\Models;

use App\Enums\MailboxType;
use Carbon\Carbon;
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
        'last_fetch_attempted_at',
        'last_fetch_succeeded_at',
        'provider_cursor',
        'consecutive_fetch_failures',
        'last_fetch_error_category',
        'last_fetch_error_code',
        'last_fetch_error_message',
        'next_fetch_at',
        'fetch_queued_at',
        'fetch_started_at',
        'pending_inbound_count',
        'consecutive_processing_failures',
        'last_processing_succeeded_at',
        'last_processing_failed_at',
        'last_processing_error_code',
        'last_processing_error_message',
    ];

    protected $hidden = [
        'credentials',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MailboxType::class,
            'credentials' => 'encrypted:array',
            'incoming_settings' => 'json',
            'outgoing_settings' => 'json',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_fetch_attempted_at' => 'datetime',
            'last_fetch_succeeded_at' => 'datetime',
            'consecutive_fetch_failures' => 'integer',
            'next_fetch_at' => 'datetime',
            'fetch_queued_at' => 'datetime',
            'fetch_started_at' => 'datetime',
            'pending_inbound_count' => 'integer',
            'consecutive_processing_failures' => 'integer',
            'last_processing_succeeded_at' => 'datetime',
            'last_processing_failed_at' => 'datetime',
        ];
    }

    public function ingestionHealthStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->last_fetch_error_category === 'authentication') {
            return 'authentication_required';
        }
        if ($this->consecutive_fetch_failures > 0) {
            return 'fetch_failing';
        }
        if ($this->consecutive_processing_failures > 0) {
            return 'processing_failing';
        }
        if ($this->fetch_started_at !== null) {
            return 'fetching';
        }
        if ($this->fetch_queued_at !== null) {
            return 'queued';
        }
        if ($this->last_fetch_succeeded_at === null) {
            return 'never_fetched';
        }

        $staleAfterMinutes = max(15, $this->polling_interval * 3);

        return Carbon::parse($this->last_fetch_succeeded_at)->isBefore(now()->subMinutes($staleAfterMinutes))
            ? 'stale'
            : 'healthy';
    }

    public function ingestionQueueStatus(): string
    {
        if ($this->fetch_started_at !== null) {
            return 'running';
        }

        return $this->fetch_queued_at !== null ? 'queued' : 'idle';
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
