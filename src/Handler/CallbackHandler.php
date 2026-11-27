<?php

declare(strict_types=1);

namespace Componenta\Queue\Handler;

use Closure;
use Componenta\Queue\Message;
use Componenta\Queue\MessageHandlerInterface;
use Componenta\Queue\ProcessingContext;
use Override;

final readonly class CallbackHandler implements MessageHandlerInterface
{
    /** @var Closure(Message, ProcessingContext): void */
    private Closure $callback;

    /** @param callable(Message, ProcessingContext): void $callback */
    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    #[Override]
    public function handle(Message $message, ProcessingContext $context): void
    {
        ($this->callback)($message, $context);
    }
}
