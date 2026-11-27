<?php

declare(strict_types=1);

namespace Componenta\Queue;

use Componenta\Queue\Exception\InvalidMessageException;

final readonly class Message
{
    public function __construct(
        public string $id,
        public MessageType $type,
        public string $body,
        public Headers $headers = new Headers(),
    ) {
        if ($id === '' || preg_match('//u', $id) !== 1 || preg_match('/\p{Cc}/u', $id) !== 0) {
            throw new InvalidMessageException('Message ID must be a non-empty UTF-8 string without control characters.');
        }

        foreach ($headers->values as $name => $value) {
            if (str_starts_with($name, 'componenta-')) {
                throw new InvalidMessageException('Application headers cannot use the reserved componenta- prefix.');
            }
        }
    }
}
