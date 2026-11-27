<?php

declare(strict_types=1);

namespace Componenta\Queue;

final readonly class FailedMessage
{
    public function __construct(
        public FailureSummary $summary,
        public RawMessage $raw,
        public DeliveryMetadata $metadata,
        public FailureDetails $details,
    ) {
    }
}
