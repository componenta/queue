<?php

declare(strict_types=1);

namespace Componenta\Queue\Handler;

use Componenta\Queue\MessageHandlerInterface;
use Componenta\Queue\MessageType;

/** Binds one exact protocol type/version to an intentionally shared handler. */
final readonly class HandlerRegistration
{
    public function __construct(
        public MessageType $type,
        public MessageHandlerInterface $handler,
    ) {
    }
}
