<?php

namespace App\Services\Email;

final class InboundEmailIdentity
{
    public static function key(string $mailboxId, string $providerMessageId): string
    {
        return hash('sha256', $mailboxId."\0".$providerMessageId);
    }
}
