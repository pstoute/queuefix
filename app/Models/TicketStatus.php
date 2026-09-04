<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class TicketStatus extends Model
{
    /** @use HasFactory<\Database\Factories\TicketStatusFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'color',
        'icon',
        'sort_order',
        'is_default',
        'is_closed',
        'is_system',
        'is_customer_visible',
        'pauses_sla',
    ];

    /** @var list<string> */
    protected $hidden = [
        'pauses_sla',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'is_closed' => 'boolean',
            'is_system' => 'boolean',
            'is_customer_visible' => 'boolean',
            'pauses_sla' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (TicketStatus $status): void {
            if ($status->is_system) {
                throw new LogicException('System ticket statuses cannot be deleted.');
            }

            if ($status->is_default) {
                throw new LogicException('The default ticket status cannot be deleted.');
            }

            if ($status->tickets()->exists()) {
                throw new LogicException('Ticket statuses in use cannot be deleted.');
            }
        });
    }

    /**
     * Store false defaults as null so one portable unique index permits many
     * non-default statuses but never more than one default.
     */
    public function setIsDefaultAttribute(bool $value): void
    {
        $this->attributes['is_default'] = $value ? 1 : null;
    }

    public function getIsDefaultAttribute(mixed $value): bool
    {
        return (bool) $value;
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'ticket_status_id');
    }

    /** @param Builder<TicketStatus> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** @param Builder<TicketStatus> $query */
    public function scopeCustomerVisible(Builder $query): void
    {
        $query->where('is_customer_visible', true);
    }

    public static function defaultStatus(): self
    {
        return self::query()->where('is_default', true)->sole();
    }

    public static function systemClosedStatus(): self
    {
        return self::query()
            ->where('is_system', true)
            ->where('is_closed', true)
            ->orderByDesc('sort_order')
            ->firstOrFail();
    }
}
