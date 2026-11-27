<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\FailedMessage;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailureStatus;
use Componenta\Queue\FailureSummary;
use DateTimeImmutable;

/** @internal */
final class FailureRecord
{
    public FailureStatus $status = FailureStatus::Pending;
    public ?int $redriveDelay = null;

    public function __construct(
        public readonly string $id,
        public readonly int $sequence,
        public readonly Entry $entry,
        public readonly int $generation,
        public readonly DeliveryMetadata $metadata,
        public readonly FailureDetails $details,
        public readonly DateTimeImmutable $failedAt,
    ) {
    }

    public function summary(): FailureSummary
    {
        return new FailureSummary(
            $this->id,
            $this->metadata->source,
            $this->entry->messageId,
            $this->failedAt,
            $this->details->reason,
            $this->status,
        );
    }

    public function snapshot(): FailedMessage
    {
        return new FailedMessage($this->summary(), $this->entry->raw, $this->metadata, $this->details);
    }
}
