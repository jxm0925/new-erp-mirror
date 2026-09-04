<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ApprovalActionExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $actionCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
