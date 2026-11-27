<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\ReceiverClosedException;

/** @internal Shared only by one receiver and its private delivery capability. */
final class Session
{
    public bool $closed = false;
    public ?DeliveryState $active = null;

    public function assertAvailable(): void
    {
        if ($this->closed) {
            throw new ReceiverClosedException('The receiver session is closed.');
        }

        if ($this->active !== null) {
            throw new DeliveryStateException('Settle or close the outstanding delivery before receiving again.');
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->active?->close();
        $this->active = null;
    }
}
