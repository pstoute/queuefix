<?php

namespace App\Services\Email;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailProcessorService
{
    public function __construct(
        private TicketService $ticketService,
    ) {}

    public function processInboundEmail(array $emailData, Mailbox $mailbox): Ticket
    {
        $customer = $this->findOrCreateCustomer($emailData);
        $existingTicket = $this->findExistingTicket($emailData);

        if ($existingTicket) {
            return $this->appendToTicket($existingTicket, $emailData, $customer);
        }

        return $this->createNewTicket($emailData, $customer, $mailbox);
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

        if (preg_match('/\[ST-(\d+)\]/', $emailData['subject'] ?? '', $matches)) {
            $ticket = Ticket::where('ticket_number', 'ST-' . $matches[1])->first();
            if ($ticket) {
                return $ticket;
            }
        }

        return null;
    }

    private function createNewTicket(array $emailData, Customer $customer, Mailbox $mailbox): Ticket
    {
        $ticket = $this->ticketService->createTicket([
            'subject' => $emailData['subject'] ?? '(No Subject)',
            'body' => $emailData['body_html'] ?? $emailData['body_text'] ?? '',
        ], $customer, $mailbox->id);

        $message = $ticket->messages()->first();

        if ($message) {
            $message->update([
                'message_id' => $emailData['message_id'] ?? null,
                'in_reply_to' => $emailData['in_reply_to'] ?? null,
                'references' => $emailData['references'] ?? null,
            ]);

            $this->processAttachments($message, $emailData['attachments'] ?? []);
        }

        return $ticket;
    }

    private function appendToTicket(Ticket $ticket, array $emailData, Customer $customer): Ticket
    {
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed])) {
            $this->ticketService->updateStatus($ticket, TicketStatus::Open);
        }

        $message = $this->ticketService->addMessage($ticket, [
            'type' => MessageType::Reply,
            'body_text' => $emailData['body_text'] ?? null,
            'body_html' => $emailData['body_html'] ?? null,
            'sender_type' => Customer::class,
            'sender_id' => $customer->id,
            'message_id' => $emailData['message_id'] ?? null,
            'in_reply_to' => $emailData['in_reply_to'] ?? null,
            'references' => $emailData['references'] ?? null,
        ]);

        $this->processAttachments($message, $emailData['attachments'] ?? []);

        return $ticket->fresh();
    }

    private function processAttachments(Message $message, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'] ?? 'unnamed';
            $path = 'attachments/' . $message->ticket_id . '/' . Str::uuid() . '_' . $filename;

            Storage::disk('local')->put($path, $attachment['content']);

            Attachment::create([
                'message_id' => $message->id,
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $attachment['mime_type'] ?? 'application/octet-stream',
                'size' => strlen($attachment['content']),
            ]);
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
