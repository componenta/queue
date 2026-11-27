<?php

declare(strict_types=1);

namespace Componenta\Queue\Exception;

use RuntimeException;
use Throwable;

/** Retains every independent failure without replacing the infrastructure cause. */
final class ProcessingException extends RuntimeException
{
    public function __construct(
        public readonly ?Throwable $processingError,
        public readonly Throwable $infrastructureError,
        public readonly ?Throwable $closeError = null,
    ) {
        parent::__construct('Queue processing could not complete safely.', 0, $infrastructureError);
    }
}
