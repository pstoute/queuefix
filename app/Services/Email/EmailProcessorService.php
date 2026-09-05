<?php

namespace App\Services\Email;

use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Exceptions\AttachmentRejected;
use App\Exceptions\InboundEmailRejected;
use App\Models\Customer;
use App\Models\InboundEmailReceipt;
use App\Models\Mailbox;
use App\Models\MailboxAlias;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Attachments\AttachmentService;
use App\Services\TicketService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnexpectedValueException;

class EmailProcessorService
{
    public function __construct(
        private TicketService $ticketService,
        private AttachmentService $attachmentService,
        private InboundEmailNormalizer $normalizer,
    ) {}

    public function processInboundEmail(array $emailData, Mailbox $mailbox): ?Ticket
    {
        $idempotencyKey = $this->idempotencyKey($emailData, $mailbox);
        $existingReceipt = $this->findReceipt($mailbox, $idempotencyKey);

        if ($existingReceipt) {
            return $existingReceipt->ticket;
        }

        try {
            $emailData = $this->normalizer->normalize($emailData);
        } catch (InboundEmailRejected $exception) {
            InboundEmailReceipt::firstOrCreate(
                [
                    'mailbox_id' => $mailbox->id,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'disposition' => 'rejected',
                    'rejection_reason' => $exception->reasonCode,
                ],
            );

            return null;
        }

        $storedAttachmentPaths = [];

        try {
            return DB::transaction(function () use ($emailData, $mailbox, $idempotencyKey, &$storedAttachmentPaths) {
                $receipt = InboundEmailReceipt::create([
                    'mailbox_id' => $mailbox->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                try {
                    $customer = $this->findOrCreateCustomer($emailData);
                    $existingTicket = $this->findExistingTicket($emailData, $customer, $mailbox);

                    $ticket = $existingTicket
                        ? $this->appendToTicket($existingTicket, $emailData, $customer, $idempotencyKey, $storedAttachmentPaths)
                        : $this->createNewTicket(
                            $emailData,
                            $customer,
                            $mailbox,
                            $this->resolveDepartment($emailData, $mailbox),
                            $idempotencyKey,
                            $storedAttachmentPaths,
                        );

                    $receipt->update(['ticket_id' => $ticket->id]);

                    return $ticket;
                } catch (Throwable $exception) {
                    // Clean up while this transaction still owns the receipt's
                    // unique-key lock, before a competing retry can write here.
                    $this->deleteStoredAttachments($storedAttachmentPaths);
                    $storedAttachmentPaths = [];

                    throw $exception;
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            $winningReceipt = $this->findReceipt($mailbox, $idempotencyKey);

            if ($winningReceipt) {
                return $winningReceipt->ticket;
            }
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $emailData
     */
    private function idempotencyKey(array $emailData, Mailbox $mailbox): string
    {
        $providerMessageId = trim((string) ($emailData['provider_message_id'] ?? ''));

        if ($providerMessageId === '') {
            throw new UnexpectedValueException('Inbound email is missing its provider message identity.');
        }

        return InboundEmailIdentity::key($mailbox->id, $providerMessageId);
    }

    private function findReceipt(Mailbox $mailbox, string $idempotencyKey): ?InboundEmailReceipt
    {
        return InboundEmailReceipt::with('ticket')
            ->where('mailbox_id', $mailbox->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function findOrCreateCustomer(array $emailData): Customer
    {
        return Customer::firstOrCreate(
            ['email' => strtolower($emailData['from_email'])],
            [
                'name' => $emailData['from_name'] ?? explode('@', $emailData['from_email'])[0],
            ]
        );
    }

    private function findExistingTicket(array $emailData, Customer $customer, Mailbox $mailbox): ?Ticket
    {
        if (! empty($emailData['in_reply_to'])) {
            $ticket = $this->findTicketByMessageId($emailData['in_reply_to'], $customer, $mailbox);
            if ($ticket) {
                return $ticket;
            }
        }

        if (! empty($emailData['references'])) {
            $ticket = $this->findTicketByMessageIds($emailData['references'], $customer, $mailbox);
            if ($ticket) {
                return $ticket;
            }
        }

        $prefix = Setting::get('ticket_prefix', 'QF');
        $escapedPrefix = preg_quote($prefix, '/');
        if (preg_match('/\['.$escapedPrefix.'-(\d+)\]/', $emailData['subject'] ?? '', $matches)) {
            $ticket = Ticket::where('ticket_number', $prefix.'-'.$matches[1])
                ->where('customer_id', $customer->id)
                ->where('mailbox_id', $mailbox->id)
                ->first();
            if ($ticket) {
                return $ticket;
            }
        }

        return null;
    }

    private function findTicketByMessageId(string $messageId, Customer $customer, Mailbox $mailbox): ?Ticket
    {
        return $this->findTicketByMessageIds([$messageId], $customer, $mailbox);
    }

    /** @param list<string> $messageIds */
    private function findTicketByMessageIds(array $messageIds, Customer $customer, Mailbox $mailbox): ?Ticket
    {
        return Ticket::where('customer_id', $customer->id)
            ->where('mailbox_id', $mailbox->id)
            ->whereHas('messages', fn ($query) => $query->whereIn('message_id', $messageIds))
            ->first();
    }

    private function resolveDepartment(array $emailData, Mailbox $mailbox): ?string
    {
        $toEmail = strtolower($emailData['to_email'] ?? '');

        if ($toEmail) {
            $alias = MailboxAlias::where('email', $toEmail)->first();
            if ($alias) {
                return $alias->department_id;
            }
        }

        return $mailbox->department_id;
    }

    /**
     * @param  list<string>  $storedAttachmentPaths
     *
     * @param-out list<string> $storedAttachmentPaths
     */
    private function createNewTicket(
        array $emailData,
        Customer $customer,
        Mailbox $mailbox,
        ?string $departmentId,
        string $idempotencyKey,
        array &$storedAttachmentPaths,
    ): Ticket {
        $ticket = $this->ticketService->createTicket([
            'subject' => $emailData['subject'] ?? '(No Subject)',
            'body' => $emailData['body_html'] ?? $emailData['body_text'] ?? '',
        ], $customer, $mailbox->id, $departmentId);

        $message = $ticket->messages()->first();

        if ($message) {
            $refs = $emailData['references'] ?? null;
            if (is_array($refs)) {
                $refs = implode(' ', $refs);
            }

            $message->update([
                'message_id' => $emailData['message_id'] ?? null,
                'in_reply_to' => $emailData['in_reply_to'] ?? null,
                'references' => $refs,
            ]);

            $this->processAttachments(
                $message,
                $emailData['attachments'] ?? [],
                $emailData['attachment_rejection'] ?? null,
                $idempotencyKey,
                $storedAttachmentPaths,
            );
        }

        return $ticket;
    }

    /**
     * @param  list<string>  $storedAttachmentPaths
     *
     * @param-out list<string> $storedAttachmentPaths
     */
    private function appendToTicket(
        Ticket $ticket,
        array $emailData,
        Customer $customer,
        string $idempotencyKey,
        array &$storedAttachmentPaths,
    ): Ticket {
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed])) {
            $this->ticketService->updateStatus($ticket, TicketStatus::Open);
        }

        $refs = $emailData['references'] ?? null;
        if (is_array($refs)) {
            $refs = implode(' ', $refs);
        }

        $message = $this->ticketService->addMessage($ticket, [
            'type' => MessageType::Reply,
            'body_text' => $emailData['body_text'] ?? null,
            'body_html' => $emailData['body_html'] ?? null,
            'sender_type' => Customer::class,
            'sender_id' => $customer->id,
            'message_id' => $emailData['message_id'] ?? null,
            'in_reply_to' => $emailData['in_reply_to'] ?? null,
            'references' => $refs,
        ]);

        $this->processAttachments(
            $message,
            $emailData['attachments'] ?? [],
            $emailData['attachment_rejection'] ?? null,
            $idempotencyKey,
            $storedAttachmentPaths,
        );

        return $ticket->fresh();
    }

    /**
     * @param  array{reason_code?: mixed, reported_count?: mixed, reported_bytes?: mixed}|null  $providerRejection
     * @param  list<string>  $storedAttachmentPaths
     *
     * @param-out list<string> $storedAttachmentPaths
     */
    private function processAttachments(
        Message $message,
        array $attachments,
        ?array $providerRejection,
        string $idempotencyKey,
        array &$storedAttachmentPaths,
    ): void {
        if ($providerRejection !== null) {
            $this->attachmentService->recordInboundRejection($message, $providerRejection);

            return;
        }

        try {
            $this->attachmentService->storeForMessage(
                $message,
                $attachments,
                $idempotencyKey,
                $storedAttachmentPaths,
            );
        } catch (AttachmentRejected $exception) {
            if (! $this->attachmentService->isTerminalRejection($exception)) {
                throw $exception;
            }

            $reportedBytes = 0;
            foreach ($attachments as $attachment) {
                $content = $attachment['content'] ?? null;
                if (is_string($content)) {
                    $reportedBytes = min(2_147_483_647, $reportedBytes + strlen($content));
                }
            }

            $this->attachmentService->recordInboundRejection($message, [
                'reason_code' => $exception->reasonCode,
                'reported_count' => count($attachments),
                'reported_bytes' => $reportedBytes,
            ]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteStoredAttachments(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk((string) config('attachments.disk'))->delete($paths);
        }
    }

    public function buildOutboundHeaders(Ticket $ticket, ?Message $lastMessage = null): array
    {
        $headers = [
            'Subject' => "[{$ticket->ticket_number}] {$ticket->subject}",
        ];

        if ($lastMessage && $lastMessage->message_id) {
            $headers['In-Reply-To'] = $lastMessage->message_id;
        }

        $references = $ticket->messages()
            ->whereNotNull('message_id')
            ->orderBy('created_at')
            ->pluck('message_id')
            ->toArray();

        if (! empty($references)) {
            $headers['References'] = implode(' ', $references);
        }

        return $headers;
    }
}
