<?php

declare(strict_types=1);

namespace Componenta\Queue;

final readonly class ProcessingContext
{
    public function __construct(
        public DeliveryMetadata $metadata,
        public ?LeaseInterface $lease,
    ) {
    }
}
