<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class EscalationActionException extends RuntimeException
{
    public function __construct(
        public readonly int $actionOrder,
        public readonly string $actionType,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
