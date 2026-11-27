<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface ReceiverInterface
{
    /** At most one active delivery per receiver; null means no work returned. */
    public function receive(int $waitMilliseconds = 0): ?DeliveryInterface;

    /** Idempotently invalidate local capabilities before fallible cleanup. */
    public function close(): void;
}
