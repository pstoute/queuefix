<?php

namespace App\Models;

use App\Enums\TicketActivityActorType;
use App\Enums\TicketActivityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TicketActivity extends Model
{
    /** @use HasFactory<\Database\Factories\TicketActivityFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'actor_id',
        'actor_type',
        'event_type',
        'before',
        'after',
        'summary',
        'correlation_id',
        'customer_visible',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_type' => TicketActivityActorType::class,
            'event_type' => TicketActivityType::class,
            'before' => 'array',
            'after' => 'array',
            'customer_visible' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ticket activities are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Ticket activities are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<TicketActivity>  $query
     * @return Builder<TicketActivity>
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('customer_visible', true);
    }
}
