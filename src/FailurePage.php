<?php

declare(strict_types=1);

namespace Componenta\Queue;

use InvalidArgumentException;

final readonly class FailurePage
{
    /** @var list<FailureSummary> */
    public array $items;

    /** @param array<mixed> $items */
    public function __construct(array $items, public ?string $nextCursor = null)
    {
        $copy = [];
        foreach ($items as $item) {
            if (!$item instanceof FailureSummary) {
                throw new InvalidArgumentException('Failure pages contain only summaries.');
            }
            $copy[] = $item;
        }
        if ($nextCursor === '') {
            throw new InvalidArgumentException('A continuation cursor cannot be empty.');
        }
        $this->items = $copy;
    }
}
