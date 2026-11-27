<?php

declare(strict_types=1);

namespace Componenta\Queue;

interface PublisherInterface
{
    /** Successful return means the adapter accepted the publication. */
    public function publish(Message $message, int $delayMilliseconds = 0): void;
}
