<?php

namespace App\Models;

use App\Enums\MailboxType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

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
        'reply_address_template',
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
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
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
            'imap_poll_cursor' => 'integer',
        ];
    }

    public function getDecryptedCredential(string $key): mixed
    {
        $credentials = $this->getAttribute('credentials');

        if ($credentials === null) {
            return null;
        }

        if (! is_array($credentials)) {
            throw new UnexpectedValueException('Mailbox credentials must decrypt to an array.');
        }

        return $credentials[$key] ?? null;
    }

    public function setEncryptedCredential(string $key, mixed $value): void
    {
        $this->setEncryptedCredentials([$key => $value]);
    }

    /**
     * @param  array<array-key, mixed>  $credentials
     */
    public function setEncryptedCredentials(array $credentials): void
    {
        foreach (array_keys($credentials) as $key) {
            if (! is_string($key) || $key === '') {
                throw new UnexpectedValueException('Mailbox credential keys must be non-empty strings.');
            }
        }

        if ($credentials === []) {
            return;
        }

        $this->getConnection()->transaction(function () use ($credentials): void {
            $mailbox = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $persistedCredentials = $mailbox->getAttribute('credentials');

            if ($persistedCredentials === null) {
                $persistedCredentials = [];
            }

            if (! is_array($persistedCredentials)) {
                throw new UnexpectedValueException('Mailbox credentials must decrypt to an array.');
            }

            $mailbox->setAttribute('credentials', array_replace($persistedCredentials, $credentials));
            $mailbox->save();

            $this->setRawAttributes($mailbox->getAttributes(), true);
        });
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

    /** @return HasMany<TicketReplyCapability, $this> */
    public function replyCapabilities(): HasMany
    {
        return $this->hasMany(TicketReplyCapability::class);
    }
}
