<?php

namespace App\Services\Email;

use App\Enums\MailboxType;
use App\Models\Mailbox;

class MailboxConnectorFactory
{
    public function make(Mailbox $mailbox): ?InboundEmailConnector
    {
        $type = MailboxType::tryFrom((string) $mailbox->getRawOriginal('type'));

        return match ($type) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
            default => null,
        };
    }
}
