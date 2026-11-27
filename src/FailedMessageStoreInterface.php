<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface FailedMessageStoreInterface
{
    public function find(string $failureId): ?FailedMessage;

    public function page(FailureQuery $query): FailurePage;

    public function redrive(string $failureId, int $delayMilliseconds = 0): void;

    public function discard(string $failureId): void;
}
