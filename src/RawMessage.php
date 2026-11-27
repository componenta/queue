<?php

declare(strict_types=1);

namespace Componenta\Queue;

final readonly class RawMessage
{
    public function __construct(
        public string $body,
        public Headers $headers = new Headers(),
    ) {
    }
}
