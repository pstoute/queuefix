<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\MailboxConnectorFactory;
use App\Services\Email\TicketReplyCapabilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendEmailReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private string $ticketId,
        private string $messageId,
    ) {}

    public function handle(
        EmailProcessorService $emailProcessor,
        TicketReplyCapabilityService $replyCapabilities,
        MailboxConnectorFactory $connectorFactory,
    ): void {
        $ticket = Ticket::with(['customer', 'mailbox', 'messages'])->find($this->ticketId);
        $message = Message::find($this->messageId);

        if (! $ticket || ! $message || ! $ticket->mailbox) {
            Log::error('Missing ticket, message, or mailbox for email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $mailbox = $ticket->mailbox;
        $connector = $connectorFactory->make($mailbox);

        if (! $connector || ! $connector->connect($mailbox)) {
            Log::error('Failed to connect to mailbox for sending', ['mailbox_id' => $mailbox->id]);

            return;
        }

        $lastCustomerMessage = $ticket->messages()
            ->where('sender_type', \App\Models\Customer::class)
            ->whereNotNull('message_id')
            ->latest()
            ->first();

        $headers = $emailProcessor->buildOutboundHeaders($ticket, $lastCustomerMessage);
        $replyTo = $replyCapabilities->replyAddress($ticket);

        $success = $connector->sendEmail([
            'to' => $ticket->customer->email,
            'subject' => $headers['Subject'],
            'text' => $message->body_text,
            'html' => $message->body_html,
            'headers' => $headers,
            'reply_to' => $replyTo,
        ]);

        if (! $success) {
            Log::error('Failed to send email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);
        }
    }
}
