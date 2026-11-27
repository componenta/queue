<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface DeliveryInterface
{
    public function raw(): RawMessage;

    public function metadata(): DeliveryMetadata;

    public function lease(): ?LeaseInterface;

    public function acknowledge(): void;

    public function retry(int $delayMilliseconds): void;

    public function fail(FailureDetails $failure): void;
}
