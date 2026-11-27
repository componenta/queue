<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\RawMessage;
use DateTimeImmutable;

/** @internal Mutable storage; never returned through a public capability. */
final class Entry
{
    public EntryStatus $status = EntryStatus::Ready;
    public int $generation = 1;
    public int $deliveryCount = 0;
    public int $retryCount = 0;
    public int $lifetimeDeliveries = 0;
    public int $lifetimeRedrives = 0;
    public ?object $token = null;
    public ?DateTimeImmutable $leaseUntil = null;
    public ?string $failureId = null;
    public bool $queued = false;

    /** @param array<string,string> $comparisonHeaders */
    public function __construct(
        public readonly string $messageId,
        public readonly RawMessage $raw,
        public readonly array $comparisonHeaders,
        public readonly int $originalDelay,
        public DateTimeImmutable $availableAt,
        public DateTimeImmutable $receivedAt,
    ) {
    }
}
