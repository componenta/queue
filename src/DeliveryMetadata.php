<?php

declare(strict_types=1);

namespace Componenta\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryMetadata
{
    public DateTimeImmutable $receivedAt;

    public function __construct(
        public string $source,
        public int $retryCount,
        public ?int $deliveryCount,
        public CountAccuracy $countAccuracy,
        DateTimeImmutable $receivedAt,
    ) {
        if ($source === '' || preg_match('//u', $source) !== 1 || preg_match('/\p{Cc}/u', $source) !== 0) {
            throw new InvalidArgumentException('Source must be non-empty UTF-8 without control characters.');
        }
        if ($retryCount < 0 || ($countAccuracy === CountAccuracy::Unknown ? $deliveryCount !== null : ($deliveryCount === null || $deliveryCount < 1))) {
            throw new InvalidArgumentException('Delivery counters and accuracy are inconsistent.');
        }
        $this->receivedAt = DateTimeImmutable::createFromInterface($receivedAt);
    }
}
