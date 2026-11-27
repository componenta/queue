<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface LeaseInterface
{
    /** Extend ownership from the adapter's current instant; never resurrect it. */
    public function renew(int $leaseMilliseconds): LeaseSnapshot;
}
