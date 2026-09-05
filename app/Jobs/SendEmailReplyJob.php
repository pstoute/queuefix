<?php

namespace App\Jobs;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\GmailConnector;
use App\Services\Email\ImapConnector;
use App\Services\Email\MicrosoftGraphConnector;
use App\Services\TicketCcService;
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

    public function handle(EmailProcessorService $emailProcessor, TicketCcService $ccService): void
    {
        $ticket = Ticket::with(['customer', 'mailbox', 'messages'])->find($this->ticketId);
        $message = Message::find($this->messageId);

        if (! $ticket || ! $message || ! $ticket->mailbox) {
            Log::error('Missing ticket, message, or mailbox for email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $messageType = $message->getRawOriginal('type');
        if (
            $message->ticket_id !== $ticket->id
            || ($messageType instanceof \App\Enums\MessageType ? $messageType->value : $messageType) !== \App\Enums\MessageType::Reply->value
            || $message->sender_type !== User::class
        ) {
            Log::warning('Refusing to send a non-public ticket message', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $mailbox = $ticket->mailbox;
        $connector = $this->getConnector($mailbox);

        if (! $connector->connect($mailbox)) {
            Log::error('Failed to connect to mailbox for sending', ['mailbox_id' => $mailbox->id]);

            return;
        }

        $lastCustomerMessage = $ticket->messages()
            ->where('sender_type', \App\Models\Customer::class)
            ->whereNotNull('message_id')
            ->latest()
            ->first();

        $headers = $emailProcessor->buildOutboundHeaders($ticket, $lastCustomerMessage);

        $success = $connector->sendEmail([
            'to' => $ticket->customer->email,
            'subject' => $headers['Subject'],
            'text' => $message->body_text,
            'html' => $message->body_html,
            'cc' => $ccService->outboundEmails($ticket, $message),
            'headers' => $headers,
        ]);

        if ($success) {
            $ccService->markDelivered($ticket, $message);
        } else {
            Log::error('Failed to send email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);
        }
    }

    private function getConnector(Mailbox $mailbox): ImapConnector|GmailConnector|MicrosoftGraphConnector
    {
        return match (MailboxType::from((string) $mailbox->getRawOriginal('type'))) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
        };
    }
}
