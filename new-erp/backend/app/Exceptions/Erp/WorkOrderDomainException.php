<?php

namespace App\Exceptions\Erp;

use RuntimeException;

/**
 * Structured domain failure for the Work Order API.
 *
 * Keeping the error code and current-state details beside the exception lets
 * the HTTP adapter distinguish a stale version, an idempotency collision and
 * a quantity conflict without making controllers re-implement domain rules.
 */
class WorkOrderDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
