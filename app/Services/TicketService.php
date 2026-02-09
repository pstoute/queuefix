<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private SlaService $slaService,
    ) {}

    public function createTicket(array $data, Customer $customer, ?string $mailboxId = null, ?string $departmentId = null): Ticket
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $customer, $mailboxId, $departmentId) {
                    $ticket = Ticket::create([
                        'subject' => $data['subject'],
                        'status' => TicketStatus::Open,
                        'priority' => $data['priority'] ?? TicketPriority::Normal,
                        'customer_id' => $customer->id,
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'mailbox_id' => $mailboxId,
                        'department_id' => $departmentId,
                        'last_activity_at' => now(),
                    ]);

                    if (! empty($data['body'])) {
                        $this->addMessage($ticket, [
                            'type' => MessageType::Reply,
                            'body_text' => strip_tags($data['body']),
                            'body_html' => $data['body'],
                            'sender_type' => Customer::class,
                            'sender_id' => $customer->id,
                        ]);
                    }

                    $this->slaService->initializeTimer($ticket);

                    return $ticket;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }
    }

    public function addMessage(Ticket $ticket, array $data): Message
    {
        $message = $ticket->messages()->create([
            'sender_type' => $data['sender_type'],
            'sender_id' => $data['sender_id'],
            'type' => $data['type'],
            'body_text' => $data['body_text'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references' => $data['references'] ?? null,
        ]);

        $ticket->update(['last_activity_at' => now()]);

        if ($data['type'] === MessageType::Reply || $data['type'] === MessageType::Reply->value) {
            $senderIsAgent = $data['sender_type'] === User::class;

            if ($senderIsAgent && $ticket->slaTimer && ! $ticket->slaTimer->first_responded_at) {
                $this->slaService->recordFirstResponse($ticket);
            }
        }

        return $message;
    }

    public function updateStatus(Ticket $ticket, TicketStatus $newStatus): Ticket
    {
        $oldStatus = $ticket->status;
        $ticket->update([
            'status' => $newStatus,
            'last_activity_at' => now(),
        ]);

        $this->slaService->handleStatusChange($ticket, $oldStatus, $newStatus);

        if ($newStatus === TicketStatus::Resolved || $newStatus === TicketStatus::Closed) {
            $this->slaService->recordResolution($ticket);
        }

        return $ticket->fresh();
    }

    public function assignTicket(Ticket $ticket, ?User $agent): Ticket
    {
        $ticket->update([
            'assigned_to' => $agent?->id,
            'last_activity_at' => now(),
        ]);

        return $ticket->fresh();
    }

    public function mergeTickets(Ticket $primary, Ticket $secondary): Ticket
    {
        return DB::transaction(function () use ($primary, $secondary) {
            $secondary->messages()->update(['ticket_id' => $primary->id]);

            $secondaryTags = $secondary->tags()->pluck('tags.id')->toArray();
            $primary->tags()->syncWithoutDetaching($secondaryTags);

            $secondary->update(['status' => TicketStatus::Closed]);
            $primary->update(['last_activity_at' => now()]);

            return $primary->fresh();
        });
    }

    public function getNextTicketNumber(): string
    {
        $prefix = Setting::get('ticket_prefix', 'QF');
        $currentCounter = (int) Setting::get('ticket_counter', '0');

        return $prefix . '-' . ($currentCounter + 1);
    }
}
