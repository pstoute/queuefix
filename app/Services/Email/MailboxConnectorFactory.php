<?php

namespace App\Services\Email;

use App\Contracts\MailboxConnector;
use App\Enums\MailboxType;
use App\Models\Mailbox;

class MailboxConnectorFactory
{
    public function resolve(Mailbox $mailbox): MailboxConnector
    {
        return match (MailboxType::from((string) $mailbox->getRawOriginal('type'))) {
            MailboxType::Imap => app(ImapConnector::class),
            MailboxType::Gmail => app(GmailConnector::class),
            MailboxType::Microsoft => app(MicrosoftGraphConnector::class),
        };
    }
}
