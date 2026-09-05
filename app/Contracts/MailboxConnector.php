<?php

namespace App\Contracts;

use App\Exceptions\MailboxFetchException;
use App\Models\Mailbox;
use DateTimeInterface;

interface MailboxConnector
{
    public function connect(Mailbox $mailbox): bool;

    /** @return list<array<string, mixed>> */
    public function fetchNewEmails(?DateTimeInterface $since = null): array;

    /** @return array{success: bool, message: string} */
    public function testConnection(Mailbox $mailbox): array;

    public function lastFailure(): ?MailboxFetchException;

    public function providerCursor(): ?string;
}
