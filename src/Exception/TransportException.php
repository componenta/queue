<?php

declare(strict_types=1);

namespace Componenta\Queue\Exception;

use Componenta\Queue\OperationOutcome;
use RuntimeException;
use Throwable;

class TransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly OperationOutcome $outcome = OperationOutcome::NotApplied,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
