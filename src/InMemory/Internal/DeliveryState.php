<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\Exception\DeliveryLostException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\LeaseSnapshot;
use Componenta\Queue\RawMessage;
use DateTimeImmutable;

/** @internal Private authority shared by the delivery and its restricted lease. */
final class DeliveryState
{
    private LocalStatus $status = LocalStatus::Active;

    public function __construct(
        private readonly Store $store,
        private readonly Session $session,
        private readonly Entry $entry,
        private readonly object $token,
        public readonly RawMessage $raw,
        public readonly DeliveryMetadata $metadata,
    ) {
    }

    public function acknowledge(): void
    {
        $this->ownedNow();
        $this->store->acknowledge($this->entry);
        $this->settled();
    }

    public function retry(int $delayMilliseconds): void
    {
        $this->assertActive();

        if ($delayMilliseconds < 0) {
            throw new \InvalidArgumentException('Retry delay must be nonnegative.');
        }

        $now = $this->ownedNow();
        $this->store->retry($this->entry, $delayMilliseconds, $now);
        $this->settled();
    }

    public function fail(FailureDetails $failure): void
    {
        $now = $this->ownedNow();
        $this->store->fail($this->entry, $failure, $now);
        $this->settled();
    }

    public function renew(int $leaseMilliseconds): LeaseSnapshot
    {
        $this->assertActive();

        if ($leaseMilliseconds < 1) {
            throw new \InvalidArgumentException('Renewal duration must be positive.');
        }

        $now = $this->ownedNow();
        $expiresAt = Time::after($now, $leaseMilliseconds);
        $snapshot = new LeaseSnapshot($expiresAt);
        $this->entry->leaseUntil = $expiresAt;

        return $snapshot;
    }

    public function close(): void
    {
        if ($this->status === LocalStatus::Active) {
            $this->status = LocalStatus::Closed;
        }
    }

    private function assertActive(): void
    {
        if ($this->status === LocalStatus::Lost) {
            throw new DeliveryLostException('The delivery no longer owns its reservation.');
        }

        if ($this->status !== LocalStatus::Active || $this->session->closed) {
            throw new DeliveryStateException('The delivery capability is no longer active.');
        }
    }

    private function ownedNow(): DateTimeImmutable
    {
        $this->assertActive();

        try {
            return $this->store->ownedNow($this->entry, $this->token);
        } catch (DeliveryLostException $error) {
            $this->status = LocalStatus::Lost;
            $this->session->closed = true;
            $this->session->active = null;

            throw $error;
        }
    }

    private function settled(): void
    {
        $this->status = LocalStatus::Settled;
        $this->session->active = null;
    }
}
