<?php

declare(strict_types=1);

namespace Componenta\Queue;

use InvalidArgumentException;

final readonly class FailureQuery
{
    public function __construct(
        public int $limit,
        public ?string $cursor = null,
        public ?FailureStatus $status = null,
    ) {
        if ($limit < 1 || $cursor === '') {
            throw new InvalidArgumentException('Page limit must be positive and a cursor, if present, non-empty.');
        }
    }
}
