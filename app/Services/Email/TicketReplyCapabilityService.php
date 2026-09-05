<?php

namespace App\Services\Email;

use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\Ticket;
use App\Models\TicketReplyCapability;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

final class TicketReplyCapabilityService
{
    public const TOKEN_PLACEHOLDER = '{token}';

    public const TOKEN_BYTES = 24;

    public const TOKEN_HEX_LENGTH = 48;

    public function templateIsValid(mixed $template): bool
    {
        if (! is_string($template)) {
            return false;
        }

        $template = trim($template);
        if ($template === '' || substr_count($template, self::TOKEN_PLACEHOLDER) !== 1) {
            return false;
        }

        $address = str_replace(self::TOKEN_PLACEHOLDER, str_repeat('a', self::TOKEN_HEX_LENGTH), $template);
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        [$localPart] = explode('@', $address, 2);

        return strlen($localPart) <= 64;
    }

    public function replyAddress(Ticket $ticket): ?string
    {
        return DB::transaction(function () use ($ticket): ?string {
            $lockedTicket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $lockedMailbox = Mailbox::query()
                ->whereKey($lockedTicket->mailbox_id)
                ->sharedLock()
                ->first();
            $template = $lockedMailbox?->reply_address_template;

            if (! $lockedMailbox || ! $this->templateIsValid($template)) {
                return null;
            }

            $capability = TicketReplyCapability::query()
                ->where('origin_ticket_id', $lockedTicket->id)
                ->lockForUpdate()
                ->first();

            if (! $capability) {
                $token = bin2hex(random_bytes(self::TOKEN_BYTES));

                $capability = TicketReplyCapability::create([
                    'ticket_id' => $lockedTicket->id,
                    'origin_ticket_id' => $lockedTicket->id,
                    'mailbox_id' => $lockedMailbox->id,
                    'token_hash' => hash('sha256', $token),
                    'token' => $token,
                ]);
            }

            // A capability whose origin differs from its target survived a
            // ticket merge. Never let delayed work on the closed origin move
            // a revoked or mailbox-invalid capability back to that ticket.
            if ($capability->ticket_id !== $lockedTicket->id
                && ($capability->mailbox_id !== $lockedMailbox->id || $capability->revoked_at !== null)) {
                return null;
            }

            if ($capability->mailbox_id !== $lockedMailbox->id || $capability->revoked_at !== null) {
                $token = bin2hex(random_bytes(self::TOKEN_BYTES));
                $capability->update([
                    'ticket_id' => $lockedTicket->id,
                    'mailbox_id' => $lockedMailbox->id,
                    'token_hash' => hash('sha256', $token),
                    'token' => $token,
                    'revoked_at' => null,
                ]);
            }

            $token = $capability->token;
            if (preg_match('/\A[a-f0-9]{'.self::TOKEN_HEX_LENGTH.'}\z/D', $token) !== 1) {
                throw new UnexpectedValueException('The ticket reply capability is invalid.');
            }

            return str_replace(self::TOKEN_PLACEHOLDER, $token, trim($template));
        });
    }

    /**
     * Resolve and lock the ticket that an inbound capability authorizes.
     *
     * The caller must keep its transaction open through message persistence so
     * revocation, rotation, and ticket merging cannot invalidate authorization
     * between this method and the protected mutation.
     */
    public function resolveInboundTicketForUpdate(Mailbox $mailbox, mixed $recipient, Customer $sender): ?Ticket
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Inbound reply capabilities must be resolved inside the message transaction.');
        }

        $token = $this->extractToken($mailbox->reply_address_template, $recipient);
        if ($token === null) {
            return null;
        }

        $candidateTicketId = TicketReplyCapability::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->value('ticket_id');

        if (! is_string($candidateTicketId)) {
            return null;
        }

        // Always lock the target ticket before the mailbox and capability. The
        // same ordering is used by issuance, revocation, and ticket merges.
        $ticket = Ticket::query()->whereKey($candidateTicketId)->lockForUpdate()->first();
        if (! $ticket) {
            return null;
        }

        $lockedMailbox = Mailbox::query()->whereKey($mailbox->id)->sharedLock()->first();
        $lockedToken = $this->extractToken($lockedMailbox?->reply_address_template, $recipient);
        if (! $lockedMailbox || ! $lockedToken || ! hash_equals($token, $lockedToken)) {
            return null;
        }

        $capability = TicketReplyCapability::query()
            ->where('mailbox_id', $lockedMailbox->id)
            ->where('ticket_id', $ticket->id)
            ->where('token_hash', hash('sha256', $lockedToken))
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        if (! $capability || ! hash_equals($capability->token, $lockedToken)) {
            return null;
        }

        if ($ticket->mailbox_id !== $lockedMailbox->id || $ticket->customer_id !== $sender->id) {
            return null;
        }

        return $ticket;
    }

    public function revokeForTicket(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $lockedTicket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $capabilities = TicketReplyCapability::query()
                ->where('ticket_id', $lockedTicket->id)
                ->whereNull('revoked_at')
                ->lockForUpdate();

            $capabilities->get();
            $capabilities->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function extractToken(mixed $template, mixed $recipient): ?string
    {
        if (! $this->templateIsValid($template) || ! is_string($recipient)) {
            return null;
        }

        [$prefix, $suffix] = explode(self::TOKEN_PLACEHOLDER, trim($template), 2);
        $pattern = '/\A'.preg_quote($prefix, '/').'([a-f0-9]{'.self::TOKEN_HEX_LENGTH.'})'.preg_quote($suffix, '/').'\z/iD';

        if (preg_match($pattern, trim($recipient), $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
