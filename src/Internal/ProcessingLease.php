<?php

declare(strict_types=1);

namespace Componenta\Queue\Internal;

use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\LeaseInterface;
use Componenta\Queue\LeaseSnapshot;
use Throwable;

/** @internal Only renewal authority crosses the handler boundary. */
final readonly class ProcessingLease implements LeaseInterface
{
    public function __construct(private LeaseInterface $lease, private LeaseGuard $guard)
    {
    }

    #[\Override]
    public function renew(int $leaseMilliseconds): LeaseSnapshot
    {
        if (!$this->guard->active) {
            throw new DeliveryStateException('The processing step has ended.');
        }
        if ($this->guard->failure !== null) {
            throw $this->guard->failure;
        }
        try {
            return $this->lease->renew($leaseMilliseconds);
        } catch (Throwable $error) {
            $this->guard->failure = $error;
            throw $error;
        }
    }
}
