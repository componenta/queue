<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct(DateTimeImmutable $instant)
    {
        $this->instant = DateTimeImmutable::createFromInterface($instant);
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function set(DateTimeImmutable $instant): void
    {
        $this->instant = DateTimeImmutable::createFromInterface($instant);
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('Advance must be nonnegative.');
        }

        $this->instant = $this->instant->modify('+'.$milliseconds.' milliseconds');
    }
}
