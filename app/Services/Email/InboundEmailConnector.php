<?php

namespace App\Services\Email;

use App\Models\Mailbox;

interface InboundEmailConnector
{
    public function connect(Mailbox $mailbox): bool;

    /**
     * Return lightweight provider references only. Message bodies and attachment
     * bytes must not cross the queue serialization boundary.
     *
     * @return iterable<int, array<string, scalar|null>>
     */
    public function fetchNewEmailReferences(?\DateTimeInterface $since = null): iterable;

    /**
     * Hydrate exactly one provider message inside its processing job.
     *
     * @param  array<string, scalar|null>  $providerReference
     * @return array<string, mixed>
     */
    public function fetchEmail(array $providerReference): array;

    /**
     * @param  array<string, mixed>  $emailData
     */
    public function acknowledge(array $emailData): bool;
}
