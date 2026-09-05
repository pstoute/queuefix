<?php

namespace App\Exceptions;

use UnexpectedValueException;

final class InboundEmailRejected extends UnexpectedValueException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Inbound email metadata did not satisfy the storage contract.');
    }
}
