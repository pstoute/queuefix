<?php

namespace App\Services\Email;

use App\Enums\MessageType;
use App\Enums\TicketActivityActorType;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailboxAlias;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketActivityService;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmailProcessorService
{
    public function __construct(
        private TicketService $ticketService,
        private TicketActivityService $activityService,
    ) {}

    public function processInboundEmail(
        array $emailData,
        Mailbox $mailbox,
        ?string $jobCorrelationId = null,
    ): Ticket {
        $customer = $this->findOrCreateCustomer($emailData);
        $existingTicket = $this->findExistingTicket($emailData);

        if ($existingTicket) {
            return $this->appendToTicket($existingTicket, $emailData, $customer, $jobCorrelationId);
        }

        $departmentId = $this->resolveDepartment($emailData, $mailbox);

        return $this->createNewTicket($emailData, $customer, $mailbox, $departmentId, $jobCorrelationId);
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

    private function findExistingTicket(array $emailData): ?Ticket
    {
        if (! empty($emailData['in_reply_to'])) {
            $message = Message::where('message_id', $emailData['in_reply_to'])->first();
            if ($message) {
                return $message->ticket;
            }
        }

        if (! empty($emailData['references'])) {
            $refs = is_array($emailData['references'])
                ? $emailData['references']
                : explode(' ', $emailData['references']);

            foreach ($refs as $ref) {
                $message = Message::where('message_id', trim($ref))->first();
                if ($message) {
                    return $message->ticket;
                }
            }
        }

        $prefix = Setting::get('ticket_prefix', 'QF');
        $escapedPrefix = preg_quote($prefix, '/');
        if (preg_match('/\['.$escapedPrefix.'-(\d+)\]/', $emailData['subject'] ?? '', $matches)) {
            $ticket = Ticket::where('ticket_number', $prefix.'-'.$matches[1])->first();
            if ($ticket) {
                return $ticket;
            }
        }

        return null;
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

    private function createNewTicket(
        array $emailData,
        Customer $customer,
        Mailbox $mailbox,
        ?string $departmentId = null,
        ?string $jobCorrelationId = null,
    ): Ticket {
        $ticket = $this->ticketService->createTicket([
            'subject' => $emailData['subject'] ?? '(No Subject)',
            'body' => $emailData['body_html'] ?? $emailData['body_text'] ?? '',
        ],
            $customer,
            $mailbox->id,
            $departmentId,
            $jobCorrelationId,
            $jobCorrelationId ? TicketActivityActorType::System : null,
        );

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

            $this->processAttachments($message, $emailData['attachments'] ?? [], $jobCorrelationId);
        }

        return $ticket;
    }

    private function appendToTicket(
        Ticket $ticket,
        array $emailData,
        Customer $customer,
        ?string $jobCorrelationId = null,
    ): Ticket {
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed])) {
            $this->ticketService->updateStatus(
                $ticket,
                TicketStatus::Open,
                $jobCorrelationId,
                $jobCorrelationId ? TicketActivityActorType::System : null,
            );
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

        $this->processAttachments($message, $emailData['attachments'] ?? [], $jobCorrelationId);

        return $ticket->fresh();
    }

    private function processAttachments(
        Message $message,
        array $attachments,
        ?string $jobCorrelationId = null,
    ): void {
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'] ?? 'unnamed';
            $path = 'attachments/'.$message->ticket_id.'/'.Str::uuid().'_'.$filename;

            if (! Storage::disk('local')->put($path, $attachment['content'])) {
                throw new RuntimeException("Unable to store attachment {$filename}.");
            }

            try {
                DB::transaction(function () use (
                    $message,
                    $attachment,
                    $filename,
                    $path,
                    $jobCorrelationId,
                ): void {
                    $storedAttachment = Attachment::create([
                        'message_id' => $message->id,
                        'filename' => $filename,
                        'path' => $path,
                        'mime_type' => $attachment['mime_type'] ?? 'application/octet-stream',
                        'size' => strlen($attachment['content']),
                    ]);
                    $ticket = Ticket::findOrFail($message->ticket_id);
                    $this->activityService->recordAttachmentAdded(
                        $ticket,
                        $storedAttachment,
                        $jobCorrelationId,
                        $jobCorrelationId ? TicketActivityActorType::System : null,
                    );
                });
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($path);

                throw $exception;
            }
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
