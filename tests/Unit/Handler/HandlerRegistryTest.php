<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit\Handler;

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\Exception\PermanentMessageException;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Handler\HandlerRegistration;
use Componenta\Queue\Handler\HandlerRegistry;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingContext;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

function handlerContext(): ProcessingContext
{
    return new ProcessingContext(new DeliveryMetadata('invoices', 0, 1, CountAccuracy::Exact, new DateTimeImmutable('2026-01-01T00:00:00Z')), null);
}

function handlerMessage(string $name = 'invoice.generate', int $version = 1): Message
{
    return new Message('message-1', new MessageType($name, $version), '{}', new Headers([]));
}

it('routes exactly one registered alias and schema pair without copying the input', function (): void {
    $seen = [];
    $registry = new HandlerRegistry([
        new HandlerRegistration(new MessageType('invoice.generate', 1), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen): void {
            $seen[] = ['v1', $message, $context];
        })),
        new HandlerRegistration(new MessageType('invoice.generate', 2), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen): void {
            $seen[] = ['v2', $message, $context];
        })),
        new HandlerRegistration(new MessageType('invoice-generate', 2), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen): void {
            $seen[] = ['different-alias', $message, $context];
        })),
    ]);
    $message = handlerMessage(version: 2);
    $context = handlerContext();

    $registry->handle($message, $context);

    expect($seen)->toBe([['v2', $message, $context]]);
});

it('rejects unknown aliases or versions without invoking any registered handler', function (string $name, int $version): void {
    $calls = 0;
    $registry = new HandlerRegistry([
        new HandlerRegistration(new MessageType('invoice.generate', 1), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$calls): void {
            ++$calls;
        })),
    ]);

    expect(fn () => $registry->handle(handlerMessage($name, $version), handlerContext()))->toThrow(PermanentMessageException::class)
        ->and($calls)->toBe(0);
})->with([
    'unknown alias' => ['unknown.command', 1],
    'unsupported schema' => ['invoice.generate', 2],
]);

it('rejects duplicate registrations during composition before processing work', function (): void {
    $handler = new CallbackHandler(static function (Message $message, ProcessingContext $context): void {
    });

    expect(fn () => new HandlerRegistry([
        new HandlerRegistration(new MessageType('invoice.generate', 1), $handler),
        new HandlerRegistration(new MessageType('invoice.generate', 1), $handler),
    ]))->toThrow(InvalidArgumentException::class);
});

it('consumes registration values even when an iterable repeats its keys', function (): void {
    $seen = [];
    $registrations = (static function () use (&$seen): iterable {
        foreach ([1, 2] as $version) {
            yield 'same-key' => new HandlerRegistration(new MessageType('invoice.generate', $version), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen, $version): void {
                $seen[] = $version;
            }));
        }
    })();
    $registry = new HandlerRegistry($registrations);

    $registry->handle(handlerMessage(version: 1), handlerContext());
    $registry->handle(handlerMessage(version: 2), handlerContext());

    expect($seen)->toBe([1, 2]);
});

it('retains its registration snapshot when the caller later replaces input entries', function (): void {
    $seen = [];
    $registration = new HandlerRegistration(new MessageType('invoice.generate', 1), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen): void {
        $seen[] = 'original';
    }));
    $registrations = [&$registration];
    $registry = new HandlerRegistry($registrations);
    $registration = new HandlerRegistration(new MessageType('invoice.generate', 1), new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$seen): void {
        $seen[] = 'replacement';
    }));

    $registry->handle(handlerMessage(), handlerContext());

    expect($seen)->toBe(['original']);
});

it('rejects values that are not handler registrations', function (): void {
    expect(fn () => new HandlerRegistry([new stdClass()]))->toThrow(InvalidArgumentException::class);
});

it('reports an unregistered message in an empty registry as a permanent rejection', function (): void {
    expect(fn () => (new HandlerRegistry([]))->handle(handlerMessage(), handlerContext()))->toThrow(PermanentMessageException::class);
});

it('allows an invokable handler and propagates its exception unchanged', function (): void {
    $failure = new RuntimeException('Handler failed.');
    $callback = new class ($failure) {
        public function __construct(private readonly RuntimeException $failure)
        {
        }

        public function __invoke(Message $message, ProcessingContext $context): never
        {
            throw $this->failure;
        }
    };
    $registry = new HandlerRegistry([new HandlerRegistration(new MessageType('invoice.generate', 1), new CallbackHandler($callback))]);
    $caught = null;

    try {
        $registry->handle(handlerMessage(), handlerContext());
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
});
