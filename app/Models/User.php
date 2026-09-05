<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/** @property UserRole $role */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'handle',
        'email',
        'password',
        'role',
        'is_support_manager',
        'avatar',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'is_support_manager' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->handle = $user->handle
                ? static::normalizeHandle($user->handle)
                : static::generateUniqueHandle($user->name);
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('handle')) {
                $user->handle = static::normalizeHandle($user->handle);
            }
        });
    }

    public static function normalizeHandle(string $value): string
    {
        $handle = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-_')
            ->limit(48, '')
            ->toString();

        return $handle !== '' ? $handle : 'user';
    }

    protected static function generateUniqueHandle(string $name): string
    {
        $base = Str::limit(static::normalizeHandle($name), 43, '');
        $candidate = $base;
        $suffix = 2;

        while (static::query()->where('handle', $candidate)->exists()) {
            $candidate = Str::limit($base, 43, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Get the tickets assigned to this user.
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    /**
     * Get the messages created by this user.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id')
            ->where('sender_type', self::class);
    }

    /**
     * Get the canned responses created by this user.
     */
    public function cannedResponses(): HasMany
    {
        return $this->hasMany(CannedResponse::class, 'created_by');
    }

    /**
     * Get the tickets this agent explicitly watches.
     *
     * @return BelongsToMany<Ticket, $this>
     */
    public function watchedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_watchers')->withTimestamps();
    }

    /** @return HasMany<TicketReadState, $this> */
    public function ticketReadStates(): HasMany
    {
        return $this->hasMany(TicketReadState::class);
    }

    /** @return HasMany<TicketMention, $this> */
    public function receivedTicketMentions(): HasMany
    {
        return $this->hasMany(TicketMention::class, 'mentioned_user_id');
    }
}
