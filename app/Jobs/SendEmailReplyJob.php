<?php

namespace App\Jobs;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Email\EmailProcessorService;
use App\Services\Email\GmailConnector;
use App\Services\Email\ImapConnector;
use App\Services\Email\MicrosoftGraphConnector;
use App\Services\TicketActivityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        TicketActivityService $activityService,
    ): void {
        $ticket = Ticket::with(['customer', 'mailbox', 'messages'])->find($this->ticketId);
        $message = Message::find($this->messageId);

        if (! $ticket || ! $message || ! $ticket->mailbox) {
            Log::error('Missing ticket, message, or mailbox for email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);

            if ($ticket) {
                $activityService->recordOutboundDelivery(
                    $ticket,
                    $this->messageId,
                    false,
                    $this->correlationId(),
                    'missing_delivery_context',
                );
            }

            return;
        }

        $mailbox = $ticket->mailbox;
        $connector = $this->getConnector($mailbox);

        if (! $connector || ! $connector->connect($mailbox)) {
            Log::error('Failed to connect to mailbox for sending', ['mailbox_id' => $mailbox->id]);
            $activityService->recordOutboundDelivery(
                $ticket,
                $message->id,
                false,
                $this->correlationId(),
                'mailbox_connection_failed',
            );

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
            'headers' => $headers,
        ]);

        if (! $success) {
            Log::error('Failed to send email reply', [
                'ticket_id' => $this->ticketId,
                'message_id' => $this->messageId,
            ]);
        }

        $activityService->recordOutboundDelivery(
            $ticket,
            $message->id,
            $success,
            $this->correlationId(),
            $success ? null : 'connector_send_failed',
        );
    }

    public function failed(Throwable $exception): void
    {
        $ticket = Ticket::withTrashed()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        app(TicketActivityService::class)->recordOutboundDelivery(
            $ticket,
            $this->messageId,
            false,
            $this->correlationId(),
            'job_failed:'.$exception::class,
        );
    }

    private function getConnector(Mailbox $mailbox): ImapConnector|GmailConnector|MicrosoftGraphConnector|null
    {
        return match ($mailbox->type) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
        };
    }

    private function correlationId(): string
    {
        return $this->job?->getJobId() ?? 'outbound-message:'.$this->messageId;
    }
}
