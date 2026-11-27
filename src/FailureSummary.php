<?php

declare(strict_types=1);

namespace Componenta\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FailureSummary
{
    public DateTimeImmutable $failedAt;

    public function __construct(
        public string $failureId,
        public string $source,
        public ?string $messageId,
        DateTimeImmutable $failedAt,
        public FailureReason $reason,
        public FailureStatus $status,
    ) {
        if ($failureId === '' || $source === '' || $messageId === '') {
            throw new InvalidArgumentException('Failure and source identifiers, and a known message identifier, must be non-empty.');
        }
        $this->failedAt = DateTimeImmutable::createFromInterface($failedAt);
    }
}
