<?php

namespace App\Services\Email;

use App\Models\Mailbox;

interface InboundEmailConnector
{
    public function connect(Mailbox $mailbox): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchNewEmails(?\DateTimeInterface $since = null): array;

    /**
     * @param  array<string, mixed>  $emailData
     */
    public function acknowledge(array $emailData): bool;
}
