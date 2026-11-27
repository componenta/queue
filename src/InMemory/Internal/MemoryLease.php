<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\LeaseInterface;
use Componenta\Queue\LeaseSnapshot;

/** @internal Only lease renewal is exposed to the handler. */
final readonly class MemoryLease implements LeaseInterface
{
    public function __construct(private DeliveryState $state)
    {
    }

    #[\Override]
    public function renew(int $leaseMilliseconds): LeaseSnapshot
    {
        return $this->state->renew($leaseMilliseconds);
    }
}
