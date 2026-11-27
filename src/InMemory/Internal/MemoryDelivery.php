<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\LeaseInterface;
use Componenta\Queue\RawMessage;

/** @internal The storage and session cannot be extracted from this capability. */
final readonly class MemoryDelivery implements DeliveryInterface
{
    public function __construct(private DeliveryState $state)
    {
    }

    #[\Override]
    public function raw(): RawMessage
    {
        return $this->state->raw;
    }

    #[\Override]
    public function metadata(): DeliveryMetadata
    {
        return $this->state->metadata;
    }

    #[\Override]
    public function lease(): LeaseInterface
    {
        return new MemoryLease($this->state);
    }

    #[\Override]
    public function acknowledge(): void
    {
        $this->state->acknowledge();
    }

    #[\Override]
    public function retry(int $delayMilliseconds): void
    {
        $this->state->retry($delayMilliseconds);
    }

    #[\Override]
    public function fail(FailureDetails $failure): void
    {
        $this->state->fail($failure);
    }
}
