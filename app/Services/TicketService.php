<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\TicketPriority;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketStatus;
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
                    $defaultStatus = TicketStatus::query()
                        ->where('is_default', true)
                        ->lockForUpdate()
                        ->sole();
                    $ticket = Ticket::create([
                        'subject' => $data['subject'],
                        'ticket_status_id' => $defaultStatus->id,
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

        throw new \RuntimeException('Unable to create ticket after retrying ticket-number allocation.');
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
        return DB::transaction(function () use ($ticket, $newStatus): Ticket {
            $newStatus = TicketStatus::query()->lockForUpdate()->findOrFail($newStatus->id);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $oldStatus = $ticket->status()->firstOrFail();

            if ($oldStatus->is($newStatus)) {
                return $ticket->load('status');
            }

            $updates = [
                'ticket_status_id' => $newStatus->id,
                'last_activity_at' => now(),
            ];

            $becameClosed = ! $oldStatus->is_closed && $newStatus->is_closed;
            if ($becameClosed) {
                $updates['resolved_at'] = $ticket->resolved_at ?? now();
                $updates['closed_at'] = now();
            } elseif ($oldStatus->is_closed && ! $newStatus->is_closed) {
                $updates['closed_at'] = null;
            }

            $ticket->update($updates);
            $this->slaService->handleStatusChange($ticket, $oldStatus, $newStatus);

            if ($becameClosed) {
                $this->slaService->recordResolution($ticket);
            }

            return $ticket->fresh('status');
        });
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

            $this->updateStatus($secondary, TicketStatus::systemClosedStatus());
            $primary->update(['last_activity_at' => now()]);

            return $primary->fresh();
        });
    }

    public function getNextTicketNumber(): string
    {
        $prefix = Setting::get('ticket_prefix', 'QF');
        $currentCounter = (int) Setting::get('ticket_counter', '0');

        return $prefix.'-'.($currentCounter + 1);
    }
}
