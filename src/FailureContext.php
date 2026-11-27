<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Throwable;

final readonly class FailureContext
{
    public function __construct(
        public Message $message,
        public DeliveryMetadata $metadata,
        public Throwable $error,
    ) {
    }
}
