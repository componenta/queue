<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Support;

use Closure;
use Componenta\Queue\FailedMessageStoreInterface;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\PublisherInterface;
use Componenta\Queue\ReceiverInterface;

final readonly class QueueHarness
{
    /** @param Closure(): ReceiverInterface $receiverFactory */
    public function __construct(
        public PublisherInterface $publisher,
        private Closure $receiverFactory,
        public FailedMessageStoreInterface $failures,
        public MessageCodecInterface $codec,
    ) {
    }

    public function receiver(): ReceiverInterface
    {
        return ($this->receiverFactory)();
    }
}
