<?php

declare(strict_types=1);

namespace Componenta\Queue;

use DateTimeImmutable;

final readonly class LeaseSnapshot
{
    public DateTimeImmutable $expiresAt;

    public function __construct(DateTimeImmutable $expiresAt)
    {
        $this->expiresAt = DateTimeImmutable::createFromInterface($expiresAt);
    }
}
