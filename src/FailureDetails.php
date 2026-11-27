<?php

declare(strict_types=1);

namespace Componenta\Queue;

use InvalidArgumentException;

/** Bounded, explicitly selected diagnostics; no exception message is captured automatically. */
final readonly class FailureDetails
{
    public function __construct(
        public FailureReason $reason,
        public ?string $exceptionType = null,
        public ?string $description = null,
    ) {
        if ($exceptionType !== null && ($exceptionType === '' || strlen($exceptionType) > 255 || preg_match('//u', $exceptionType) !== 1 || preg_match('/\p{Cc}/u', $exceptionType) !== 0)) {
            throw new InvalidArgumentException('Exception type must be 1..255 UTF-8 bytes without control characters.');
        }
        if ($description !== null && (strlen($description) > 2048 || preg_match('//u', $description) !== 1 || str_contains($description, "\0"))) {
            throw new InvalidArgumentException('Description must be at most 2048 UTF-8 bytes without NUL.');
        }
    }
}
