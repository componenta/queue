<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Componenta\Queue\Exception\InvalidMessageException;

final readonly class MessageType
{
    public function __construct(
        public string $name,
        public int $schemaVersion,
    ) {
        if (preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) !== 1) {
            throw new InvalidMessageException('Message type must be a stable lower-case application alias.');
        }

        if ($schemaVersion < 1) {
            throw new InvalidMessageException('Message schema version must be a positive integer.');
        }
    }
}
