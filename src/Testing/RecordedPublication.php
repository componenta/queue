<?php

declare(strict_types=1);

namespace Componenta\Queue\Testing;

use Componenta\Queue\Message;
use InvalidArgumentException;

final readonly class RecordedPublication
{
    public function __construct(
        public Message $message,
        public int $delayMilliseconds,
    ) {
        if ($delayMilliseconds < 0) {
            throw new InvalidArgumentException('Recorded publication delay must be non-negative.');
        }
    }
}
