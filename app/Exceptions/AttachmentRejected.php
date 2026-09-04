<?php

namespace App\Exceptions;

use RuntimeException;

class AttachmentRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
