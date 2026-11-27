<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\ReceiverInterface;

/** @internal Obtain this capability from InMemoryQueue. */
final readonly class MemoryReceiver implements ReceiverInterface
{
    private Session $session;

    public function __construct(private Store $store)
    {
        $this->session = new Session();
    }

    #[\Override]
    public function receive(int $waitMilliseconds = 0): ?DeliveryInterface
    {
        $this->session->assertAvailable();

        if ($waitMilliseconds < 0) {
            throw new \InvalidArgumentException('Receive wait must be nonnegative.');
        }

        $state = $this->store->claim($this->session);
        $this->session->active = $state;

        return $state === null ? null : new MemoryDelivery($state);
    }

    #[\Override]
    public function close(): void
    {
        $this->session->close();
    }
}
