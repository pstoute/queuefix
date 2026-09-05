<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CannedResponse extends Model
{
    use HasFactory, HasUuids;

    public const VISIBILITY_ALL_AGENTS = 'all_agents';

    public const VISIBILITY_CREATOR_ONLY = 'creator_only';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'body',
        'is_active',
        'visibility',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return list<string> */
    public static function visibilities(): array
    {
        return [self::VISIBILITY_ALL_AGENTS, self::VISIBILITY_CREATOR_ONLY];
    }

    /** @param Builder<CannedResponse> $query
     * @return Builder<CannedResponse>
     */
    public function scopeAvailableTo(Builder $query, User $user): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $visibility) use ($user): void {
                $visibility->where('visibility', self::VISIBILITY_ALL_AGENTS)
                    ->orWhere(function (Builder $private) use ($user): void {
                        $private->where('visibility', self::VISIBILITY_CREATOR_ONLY)
                            ->where('created_by', $user->id);
                    });
            });
    }

    public function isAvailableTo(User $user): bool
    {
        return $this->is_active
            && ($this->visibility === self::VISIBILITY_ALL_AGENTS || $this->created_by === $user->id);
    }

    /**
     * Get the user who created this canned response.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
