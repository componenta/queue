<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Support;

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\LeaseInterface;
use Componenta\Queue\LeaseSnapshot;
use Componenta\Queue\RawMessage;
use Componenta\Queue\ReceiverInterface;
use DateTimeImmutable;
use Throwable;

/** Observable port double, deliberately offers more authority than the handler may receive. */
final class WorkerProbe implements ReceiverInterface, DeliveryInterface, LeaseInterface
{
    public array $calls = [];
    public ?Throwable $receiveError = null;
    public ?Throwable $settleError = null;
    public ?Throwable $closeError = null;
    public ?Throwable $renewError = null;
    public bool $empty = false;
    public bool $hasLease = true;
    public ?FailureDetails $failure = null;
    public ?int $delay = null;
    public DeliveryMetadata $info;

    public function __construct(public RawMessage $rawMessage)
    {
        $this->info = new DeliveryMetadata('jobs', 0, 1, CountAccuracy::Exact, new DateTimeImmutable('2026-11-27T00:00:00Z'));
    }

    public function receive(int $waitMilliseconds = 0): ?DeliveryInterface
    {
        $this->calls[] = ['receive', $waitMilliseconds];
        if ($this->receiveError !== null) {
            throw $this->receiveError;
        }
        return $this->empty ? null : $this;
    }

    public function close(): void
    {
        $this->calls[] = ['close'];
        if ($this->closeError !== null) {
            throw $this->closeError;
        }
    }

    public function raw(): RawMessage
    {
        return $this->rawMessage;
    }
    public function metadata(): DeliveryMetadata
    {
        return $this->info;
    }
    public function lease(): ?LeaseInterface
    {
        return $this->hasLease ? $this : null;
    }

    public function acknowledge(): void
    {
        $this->calls[] = ['acknowledge'];
        if ($this->settleError !== null) {
            throw $this->settleError;
        }
    }

    public function retry(int $delayMilliseconds): void
    {
        $this->calls[] = ['retry', $delayMilliseconds];
        if ($this->settleError !== null) {
            throw $this->settleError;
        }
        $this->delay = $delayMilliseconds;
    }

    public function fail(FailureDetails $failure): void
    {
        $this->calls[] = ['fail', $failure->reason->value];
        if ($this->settleError !== null) {
            throw $this->settleError;
        }
        $this->failure = $failure;
    }

    public function renew(int $leaseMilliseconds): LeaseSnapshot
    {
        $this->calls[] = ['renew', $leaseMilliseconds];
        if ($this->renewError !== null) {
            throw $this->renewError;
        }
        return new LeaseSnapshot(new DateTimeImmutable('2026-11-27T00:05:00Z'));
    }
}
