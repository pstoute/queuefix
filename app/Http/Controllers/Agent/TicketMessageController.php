<?php

namespace App\Http\Controllers\Agent;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketMentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketMessageController extends Controller
{
    public function show(Ticket $ticket, Message $message): RedirectResponse
    {
        Gate::authorize('view', $ticket);

        if ($ticket->isMerged()) {
            $target = $ticket->canonicalTicket();
            if ($message->ticket_id === $target->id) {
                Gate::authorize('view', $target);

                return redirect()->to(route('agent.tickets.show', $target)."#message-{$message->id}");
            }
        }

        abort_unless($message->ticket_id === $ticket->id, 404);

        return redirect()->to(route('agent.tickets.show', $ticket)."#message-{$message->id}");
    }

    public function update(
        Request $request,
        Ticket $ticket,
        Message $message,
        TicketMentionService $mentionService,
    ): RedirectResponse {
        Gate::authorize('update', $ticket);

        /** @var User $actor */
        $actor = $request->user();
        abort_unless($message->ticket_id === $ticket->id, 404);
        abort_unless($message->getRawOriginal('type') === MessageType::InternalNote->value, 422);
        abort_unless($message->sender_type === User::class && $message->sender_id === $actor->id, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $mentionService->updateInternalNote($ticket, $message, $actor, $validated['body']);

        return back()->with('success', 'Internal note updated.');
    }
}
