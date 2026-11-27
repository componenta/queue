<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\FailedMessage;
use Componenta\Queue\FailedMessageStoreInterface;
use Componenta\Queue\FailurePage;
use Componenta\Queue\FailureQuery;

/** @internal Obtain this capability from InMemoryQueue. */
final readonly class MemoryFailureStore implements FailedMessageStoreInterface
{
    public function __construct(private Store $store)
    {
    }

    #[\Override]
    public function find(string $failureId): ?FailedMessage
    {
        return $this->store->findFailure($failureId);
    }

    #[\Override]
    public function page(FailureQuery $query): FailurePage
    {
        return $this->store->failurePage($query);
    }

    #[\Override]
    public function redrive(string $failureId, int $delayMilliseconds = 0): void
    {
        $this->store->redrive($failureId, $delayMilliseconds);
    }

    #[\Override]
    public function discard(string $failureId): void
    {
        $this->store->discard($failureId);
    }
}
