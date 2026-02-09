<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'description',
        'is_catch_all',
    ];

    protected $casts = [
        'is_catch_all' => 'boolean',
    ];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MailboxAlias::class);
    }
}
