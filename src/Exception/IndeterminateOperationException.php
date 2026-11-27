<?php

declare(strict_types=1);

namespace Componenta\Queue\Exception;

use Componenta\Queue\OperationOutcome;
use Throwable;

final class IndeterminateOperationException extends TransportException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, OperationOutcome::Indeterminate, $previous);
    }
}
