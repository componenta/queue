<?php

declare(strict_types=1);

namespace Componenta\Queue\Handler;

use Componenta\Queue\Exception\PermanentMessageException;
use Componenta\Queue\Message;
use Componenta\Queue\MessageHandlerInterface;
use Componenta\Queue\ProcessingContext;
use InvalidArgumentException;
use Override;

/** Resolves only explicitly registered aliases and schema versions. */
final readonly class HandlerRegistry implements MessageHandlerInterface
{
    /** @var array<string, array<int, MessageHandlerInterface>> */
    private array $handlers;

    /** @param iterable<HandlerRegistration> $registrations */
    public function __construct(iterable $registrations)
    {
        $handlers = [];

        foreach ($registrations as $value) {
            $registration = self::registration($value);
            $name = $registration->type->name;
            $version = $registration->type->schemaVersion;

            if (isset($handlers[$name][$version])) {
                throw new InvalidArgumentException(sprintf('Handler for message type "%s" schema %d is registered more than once.', $name, $version));
            }

            $handlers[$name][$version] = $registration->handler;
        }

        $this->handlers = $handlers;
    }

    #[Override]
    public function handle(Message $message, ProcessingContext $context): void
    {
        $handler = $this->handlers[$message->type->name][$message->type->schemaVersion] ?? null;

        if ($handler === null) {
            throw new PermanentMessageException(sprintf(
                'No handler is registered for message type "%s" schema %d.',
                $message->type->name,
                $message->type->schemaVersion,
            ));
        }

        $handler->handle($message, $context);
    }

    private static function registration(mixed $value): HandlerRegistration
    {
        if (!$value instanceof HandlerRegistration) {
            throw new InvalidArgumentException('Handler registrations must be instances of HandlerRegistration.');
        }

        return $value;
    }
}
