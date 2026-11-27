<?php

declare(strict_types=1);

namespace Componenta\Queue\Retry;

use InvalidArgumentException;

final readonly class RetryAfter
{
    public function __construct(public int $delayMilliseconds)
    {
        if ($delayMilliseconds < 0) {
            throw new InvalidArgumentException('Retry delay must be non-negative.');
        }
    }
}
