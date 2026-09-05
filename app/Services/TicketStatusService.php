<?php

namespace App\Services;

use App\Models\TicketStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketStatusService
{
    public function __construct(
        private SlaService $slaService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TicketStatus
    {
        return DB::transaction(function () use ($attributes): TicketStatus {
            $statuses = $this->lockActiveStatuses();
            $makeDefault = (bool) ($attributes['is_default'] ?? false);

            if ($statuses->where('is_default', true)->isEmpty()) {
                $makeDefault = true;
            }

            if ($makeDefault && (bool) ($attributes['is_closed'] ?? false)) {
                throw ValidationException::withMessages([
                    'is_default' => 'A closed status cannot be the default for new tickets.',
                ]);
            }

            if ($makeDefault) {
                $this->clearDefault($statuses);
            }

            $status = TicketStatus::create([
                ...$attributes,
                'is_default' => $makeDefault,
                'is_system' => false,
            ]);

            $this->assertDefaultInvariant();

            return $status;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TicketStatus $status, array $attributes): TicketStatus
    {
        return DB::transaction(function () use ($status, $attributes): TicketStatus {
            $statuses = $this->lockActiveStatuses();
            $lockedStatus = $statuses->firstWhere('id', $status->id);

            if (! $lockedStatus instanceof TicketStatus) {
                throw ValidationException::withMessages([
                    'status' => 'This status is archived or no longer exists.',
                ]);
            }

            if ($lockedStatus->is_system) {
                $attributes = Arr::only($attributes, [
                    'name',
                    'color',
                    'icon',
                    'sort_order',
                    'is_default',
                    'pauses_sla',
                ]);
            }

            $makeDefault = (bool) ($attributes['is_default'] ?? false);
            $willBeClosed = (bool) ($attributes['is_closed'] ?? $lockedStatus->is_closed);
            if ($lockedStatus->is_default && ! $makeDefault) {
                throw ValidationException::withMessages([
                    'is_default' => 'Choose another default status before removing this default.',
                ]);
            }

            if ($makeDefault && $willBeClosed) {
                throw ValidationException::withMessages([
                    'is_default' => 'A closed status cannot be the default for new tickets.',
                ]);
            }

            if ($willBeClosed !== $lockedStatus->is_closed && $lockedStatus->tickets()->exists()) {
                throw ValidationException::withMessages([
                    'is_closed' => 'Reassign all tickets before changing this status lifecycle behavior.',
                ]);
            }

            if ($makeDefault) {
                $this->clearDefault($statuses, $lockedStatus->id);
            }

            $previouslyPaused = $lockedStatus->pauses_sla;
            $lockedStatus->fill($attributes);
            $lockedStatus->is_default = $makeDefault;
            $lockedStatus->save();
            $this->slaService->handleStatusConfigurationChange($lockedStatus, $previouslyPaused);

            $this->assertDefaultInvariant();

            return $lockedStatus->fresh();
        }, 3);
    }

    public function archive(TicketStatus $status): void
    {
        DB::transaction(function () use ($status): void {
            $lockedStatus = TicketStatus::query()->lockForUpdate()->find($status->id);

            if (! $lockedStatus) {
                return;
            }

            if ($lockedStatus->is_system) {
                throw ValidationException::withMessages([
                    'status' => 'System statuses cannot be archived.',
                ]);
            }

            if ($lockedStatus->is_default) {
                throw ValidationException::withMessages([
                    'status' => 'Choose another default status before archiving this status.',
                ]);
            }

            if ($lockedStatus->tickets()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Reassign all tickets before archiving this status.',
                ]);
            }

            $lockedStatus->delete();
            $this->assertDefaultInvariant();
        }, 3);
    }

    public function restore(TicketStatus $status): TicketStatus
    {
        return DB::transaction(function () use ($status): TicketStatus {
            $lockedStatus = TicketStatus::withTrashed()->lockForUpdate()->findOrFail($status->id);
            $lockedStatus->restore();
            $this->assertDefaultInvariant();

            return $lockedStatus->fresh();
        }, 3);
    }

    /** @return Collection<int, TicketStatus> */
    private function lockActiveStatuses(): Collection
    {
        return TicketStatus::query()->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, TicketStatus> $statuses */
    private function clearDefault(Collection $statuses, ?string $exceptId = null): void
    {
        $statuses
            ->filter(fn (TicketStatus $status): bool => $status->is_default && $status->id !== $exceptId)
            ->each(function (TicketStatus $status): void {
                $status->is_default = false;
                $status->save();
            });
    }

    private function assertDefaultInvariant(): void
    {
        if (TicketStatus::query()->where('is_default', true)->count() !== 1) {
            throw new \LogicException('Exactly one active default ticket status is required.');
        }
    }
}
