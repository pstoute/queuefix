<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\TicketRatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerTicketRatingController extends Controller
{
    public function __construct(private TicketRatingService $ratingService) {}

    public function store(Request $request, Ticket $ticket): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        if ($ticket->customer_id !== $customer->id) {
            abort(403);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $feedback = trim($validated['feedback'] ?? '');
        $this->ratingService->submit(
            $ticket,
            $customer,
            (int) $validated['rating'],
            $feedback !== '' ? $feedback : null,
        );

        return back()->with('success', 'Thank you for your feedback.');
    }
}
