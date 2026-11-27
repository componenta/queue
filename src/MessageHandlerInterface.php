<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface MessageHandlerInterface
{
    /** Return only after all processing and per-message cleanup succeed. */
    public function handle(Message $message, ProcessingContext $context): void;
}
