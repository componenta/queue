<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory;

use Componenta\Queue\FailedMessageStoreInterface;
use Componenta\Queue\InMemory\Internal\MemoryFailureStore;
use Componenta\Queue\InMemory\Internal\MemoryPublisher;
use Componenta\Queue\InMemory\Internal\MemoryReceiver;
use Componenta\Queue\InMemory\Internal\Store;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\PublisherInterface;
use Componenta\Queue\ReceiverInterface;
use Psr\Clock\ClockInterface;

/**
 * Process-local queue: all work and history disappear when its state is lost.
 * Tombstones and failure history remain for this instance's lifetime.
 * Each poll inspects at most 64 rotating candidates and never waits; a positive
 * receive budget permits an early return and does not advance the injected clock.
 */
final readonly class InMemoryQueue
{
    private Store $store;

    public function __construct(
        string $queue,
        MessageCodecInterface $codec,
        ClockInterface $clock,
        int $leaseMilliseconds,
        int $maxDeliveries,
    ) {
        $this->store = new Store($queue, $codec, $clock, $leaseMilliseconds, $maxDeliveries);
    }

    public function publisher(): PublisherInterface
    {
        return new MemoryPublisher($this->store);
    }

    public function receiver(): ReceiverInterface
    {
        return new MemoryReceiver($this->store);
    }

    public function failures(): FailedMessageStoreInterface
    {
        return new MemoryFailureStore($this->store);
    }
}
