<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageCcRecipient;
use App\Models\Ticket;
use App\Models\TicketCcAudit;
use App\Models\TicketCcRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketCcService
{
    public function __construct(private EmailAddressNormalizer $normalizer) {}

    /** @return Collection<int, TicketCcRecipient> */
    public function recordInbound(
        Ticket $ticket,
        Message $message,
        mixed $rawRecipients,
        string $senderEmail,
        ?string $toEmail = null,
    ): Collection {
        return DB::transaction(function () use ($ticket, $message, $rawRecipients, $senderEmail, $toEmail): Collection {
            $this->assertPublicMessage($ticket, $message);
            $ticket = Ticket::query()->with(['customer', 'mailbox'])->lockForUpdate()->findOrFail($ticket->id);
            $sender = $this->normalizedEmail($senderEmail);
            $addresses = $this->excludePrimaryAddresses(
                $ticket,
                $this->normalizer->normalize($rawRecipients),
                array_filter([$sender, $this->normalizedEmail($toEmail)]),
            );
            $existing = TicketCcRecipient::query()
                ->where('ticket_id', $ticket->id)
                ->where('validation_state', TicketCcRecipient::VALIDATION_APPROVED)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->get()
                ->keyBy('email');
            $primaryCustomerAddedRecipients = $sender !== null
                && $sender === $this->normalizedEmail($ticket->customer->email);
            $senderIsApprovedRecipient = $sender !== null && $existing->has($sender);

            if (! $primaryCustomerAddedRecipients) {
                $addresses = array_values(array_filter(
                    $addresses,
                    fn (array $address): bool => $senderIsApprovedRecipient && $existing->has($address['email']),
                ));
            }

            $actor = $message->sender instanceof Model ? $message->sender : null;
            $recipients = $primaryCustomerAddedRecipients
                ? $this->persistTicketRecipients($ticket, $addresses, 'inbound_header', $actor, $message)
                : $existing->only(array_column($addresses, 'email'))->values();

            $this->persistMessageRecipients($ticket, $message, $recipients, 'inbound_header', $actor);
            $this->auditRecipientSet($ticket, $message, $actor, 'inbound_recipient_set', $recipients);

            return $recipients;
        }, 3);
    }

    /** @param list<string> $rawRecipients
     * @return Collection<int, TicketCcRecipient>
     */
    public function recordStaffReply(Ticket $ticket, Message $message, array $rawRecipients, User $actor): Collection
    {
        return DB::transaction(function () use ($ticket, $message, $rawRecipients, $actor): Collection {
            $this->assertPublicMessage($ticket, $message, User::class, $actor->id);
            $ticket = Ticket::query()->with(['customer', 'mailbox'])->lockForUpdate()->findOrFail($ticket->id);
            $addresses = $this->excludePrimaryAddresses($ticket, $this->normalizer->normalize($rawRecipients));
            $recipients = $this->persistTicketRecipients($ticket, $addresses, 'staff_reply', $actor, $message);

            $this->persistMessageRecipients($ticket, $message, $recipients, 'staff_reply', $actor);
            $this->auditRecipientSet($ticket, $message, $actor, 'outbound_recipient_set', $recipients, [
                'to' => $this->normalizedEmail($ticket->customer->email),
            ]);

            return $recipients;
        }, 3);
    }

    /** @param list<string> $recipientIds
     * @return Collection<int, TicketCcRecipient>
     */
    public function recordCustomerReply(
        Ticket $ticket,
        Message $message,
        array $recipientIds,
        Customer $actor,
    ): Collection {
        return DB::transaction(function () use ($ticket, $message, $recipientIds, $actor): Collection {
            $this->assertPublicMessage($ticket, $message, Customer::class, $actor->id);

            if ($ticket->customer_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'cc_recipient_ids' => 'CC recipients can only be selected for your own ticket.',
                ]);
            }

            $ids = collect($recipientIds)->unique()->values();
            $recipients = TicketCcRecipient::query()
                ->where('ticket_id', $ticket->id)
                ->whereIn('id', $ids)
                ->where('validation_state', TicketCcRecipient::VALIDATION_APPROVED)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->get();

            if ($recipients->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'cc_recipient_ids' => 'One or more CC recipients are not approved for this ticket.',
                ]);
            }

            $this->persistMessageRecipients($ticket, $message, $recipients, 'customer_portal', $actor);
            $this->auditRecipientSet($ticket, $message, $actor, 'customer_recipient_set', $recipients);

            return $recipients;
        }, 3);
    }

    public function remove(Ticket $ticket, TicketCcRecipient $recipient, User $actor): void
    {
        if ($recipient->ticket_id !== $ticket->id || $recipient->removed_at !== null) {
            return;
        }

        DB::transaction(function () use ($ticket, $recipient, $actor): void {
            $recipient = TicketCcRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            if ($recipient->ticket_id !== $ticket->id || $recipient->removed_at !== null) {
                return;
            }

            $recipient->update(['removed_at' => now()]);
            $this->createAudit($ticket, null, $recipient, $actor, 'recipient_removed', $recipient->email);
        }, 3);
    }

    /** @return list<string> */
    public function outboundEmails(Ticket $ticket, Message $message): array
    {
        if ($message->ticket_id !== $ticket->id || ! $this->isPublicMessage($message)) {
            return [];
        }

        $primary = $this->normalizedEmail($ticket->customer()->value('email'));

        return $message->ccRecipients()
            ->where('validation_state', TicketCcRecipient::VALIDATION_APPROVED)
            ->pluck('email')
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== $primary)
            ->unique()
            ->values()
            ->all();
    }

    public function markDelivered(Ticket $ticket, Message $message): void
    {
        DB::transaction(function () use ($ticket, $message): void {
            $message->ccRecipients()
                ->whereNull('delivered_at')
                ->update(['delivered_at' => now()]);

            TicketCcAudit::firstOrCreate(
                [
                    'ticket_id' => $ticket->id,
                    'message_id' => $message->id,
                    'event' => 'outbound_delivered',
                ],
                [
                    'metadata' => [
                        'to' => $this->normalizedEmail($ticket->customer()->value('email')),
                        'cc' => $this->outboundEmails($ticket, $message),
                    ],
                ],
            );
        }, 3);
    }

    /**
     * @param  list<array{email: string, display_name: string|null}>  $addresses
     * @param  list<string>  $extraExcluded
     * @return list<array{email: string, display_name: string|null}>
     */
    private function excludePrimaryAddresses(Ticket $ticket, array $addresses, array $extraExcluded = []): array
    {
        $excluded = collect([
            $this->normalizedEmail($ticket->customer->email),
            $ticket->mailbox ? $this->normalizedEmail($ticket->mailbox->email) : null,
            ...$extraExcluded,
        ])->filter()->flip();

        return array_values(array_filter(
            $addresses,
            fn (array $address): bool => ! $excluded->has($address['email']),
        ));
    }

    /**
     * @param  list<array{email: string, display_name: string|null}>  $addresses
     * @return Collection<int, TicketCcRecipient>
     */
    private function persistTicketRecipients(
        Ticket $ticket,
        array $addresses,
        string $source,
        ?Model $actor,
        ?Message $message,
    ): Collection {
        $recipients = collect();

        foreach ($addresses as $address) {
            $recipient = TicketCcRecipient::query()
                ->where('ticket_id', $ticket->id)
                ->where('email', $address['email'])
                ->lockForUpdate()
                ->first();
            $changed = false;

            if ($recipient === null) {
                $recipient = TicketCcRecipient::create([
                    'ticket_id' => $ticket->id,
                    'email' => $address['email'],
                    'display_name' => $address['display_name'],
                    'source' => $source,
                    'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
                    'added_by_type' => $actor?->getMorphClass(),
                    'added_by_id' => $actor?->getKey(),
                    'approved_at' => now(),
                ]);
                $changed = true;
            } elseif ($recipient->removed_at !== null) {
                $recipient->update([
                    'display_name' => $address['display_name'] ?? $recipient->display_name,
                    'source' => $source,
                    'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
                    'added_by_type' => $actor?->getMorphClass(),
                    'added_by_id' => $actor?->getKey(),
                    'approved_at' => now(),
                    'removed_at' => null,
                ]);
                $changed = true;
            } elseif ($recipient->display_name === null && $address['display_name'] !== null) {
                $recipient->update(['display_name' => $address['display_name']]);
            }

            if ($changed) {
                $this->createAudit($ticket, $message, $recipient, $actor, 'recipient_added', $recipient->email, [
                    'source' => $source,
                ]);
            }

            $recipients->push($recipient);
        }

        return $recipients;
    }

    /** @param Collection<int, TicketCcRecipient> $recipients */
    private function persistMessageRecipients(
        Ticket $ticket,
        Message $message,
        Collection $recipients,
        string $source,
        ?Model $actor,
    ): void {
        foreach ($recipients as $recipient) {
            MessageCcRecipient::firstOrCreate(
                ['message_id' => $message->id, 'email' => $recipient->email],
                [
                    'ticket_id' => $ticket->id,
                    'ticket_cc_recipient_id' => $recipient->id,
                    'display_name' => $recipient->display_name,
                    'source' => $source,
                    'validation_state' => TicketCcRecipient::VALIDATION_APPROVED,
                    'created_by_type' => $actor?->getMorphClass(),
                    'created_by_id' => $actor?->getKey(),
                ],
            );
        }
    }

    /** @param Collection<int, TicketCcRecipient> $recipients
     * @param  array<string, mixed>  $metadata
     */
    private function auditRecipientSet(
        Ticket $ticket,
        Message $message,
        ?Model $actor,
        string $event,
        Collection $recipients,
        array $metadata = [],
    ): void {
        TicketCcAudit::firstOrCreate(
            ['ticket_id' => $ticket->id, 'message_id' => $message->id, 'event' => $event],
            [
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'metadata' => $metadata + ['cc' => $recipients->pluck('email')->values()->all()],
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function createAudit(
        Ticket $ticket,
        ?Message $message,
        ?TicketCcRecipient $recipient,
        ?Model $actor,
        string $event,
        ?string $email = null,
        array $metadata = [],
    ): void {
        TicketCcAudit::create([
            'ticket_id' => $ticket->id,
            'message_id' => $message?->id,
            'ticket_cc_recipient_id' => $recipient?->id,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'email' => $email,
            'metadata' => $metadata,
        ]);
    }

    private function assertPublicMessage(
        Ticket $ticket,
        Message $message,
        ?string $senderType = null,
        ?string $senderId = null,
    ): void {
        if (
            $message->ticket_id !== $ticket->id
            || ! $this->isPublicMessage($message)
            || ($senderType !== null && $message->sender_type !== $senderType)
            || ($senderId !== null && $message->sender_id !== $senderId)
        ) {
            throw ValidationException::withMessages([
                'cc' => 'CC recipients are only allowed on authorized public replies.',
            ]);
        }
    }

    private function isPublicMessage(Message $message): bool
    {
        $type = $message->getRawOriginal('type');

        return ($type instanceof MessageType ? $type->value : $type) === MessageType::Reply->value;
    }

    private function normalizedEmail(mixed $email): ?string
    {
        $normalized = $this->normalizer->normalize($email);

        return $normalized[0]['email'] ?? null;
    }
}
