<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\Message;
use Componenta\Queue\PublisherInterface;

/** @internal Obtain this capability from InMemoryQueue. */
final readonly class MemoryPublisher implements PublisherInterface
{
    public function __construct(private Store $store)
    {
    }

    #[\Override]
    public function publish(Message $message, int $delayMilliseconds = 0): void
    {
        $this->store->publish($message, $delayMilliseconds);
    }
}
