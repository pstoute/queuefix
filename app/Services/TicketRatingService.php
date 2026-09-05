<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\User;
use App\Notifications\TicketLowRatingNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class TicketRatingService
{
    public function submit(Ticket $ticket, Customer $customer, int $score, ?string $feedback): TicketRating
    {
        try {
            $ticketRating = DB::transaction(function () use ($ticket, $customer, $score, $feedback): TicketRating {
                $lockedTicket = Ticket::query()
                    ->with('status')
                    ->lockForUpdate()
                    ->findOrFail($ticket->id);

                if ($lockedTicket->customer_id !== $customer->id) {
                    throw new AuthorizationException;
                }

                if (! $lockedTicket->status->is_closed || $lockedTicket->closed_at === null) {
                    throw ValidationException::withMessages([
                        'rating' => 'A rating can be submitted only after the ticket is closed.',
                    ]);
                }

                if ($lockedTicket->rating()->where('customer_id', $customer->id)->exists()) {
                    throw ValidationException::withMessages([
                        'rating' => 'A rating has already been submitted for this ticket.',
                    ]);
                }

                return $lockedTicket->rating()->create([
                    'customer_id' => $customer->id,
                    'rating' => $score,
                    'feedback' => $feedback,
                    'submitted_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'rating' => 'A rating has already been submitted for this ticket.',
            ]);
        }

        if ($ticketRating->rating <= 2) {
            $this->notifyStaff($ticketRating);
        }

        return $ticketRating;
    }

    private function notifyStaff(TicketRating $ticketRating): void
    {
        $ticketRating->loadMissing('ticket');
        $ticket = $ticketRating->ticket;

        $recipients = User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($ticket): void {
                $query->where('is_support_manager', true);

                if ($ticket->assigned_to !== null) {
                    $query->orWhere('users.id', $ticket->assigned_to);
                }
            })
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new TicketLowRatingNotification($ticketRating));
        $ticketRating->forceFill(['staff_notified_at' => now()])->save();
    }
}
