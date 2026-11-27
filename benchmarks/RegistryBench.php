<?php

declare(strict_types=1);

namespace Componenta\Queue\Benchmarks;

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Handler\HandlerRegistration;
use Componenta\Queue\Handler\HandlerRegistry;
use Componenta\Queue\Message;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingContext;
use DateTimeImmutable;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(1000)]
#[Bench\Iterations(5)]
final class RegistryBench
{
    private HandlerRegistry $registry;
    private Message $message;
    private ProcessingContext $context;
    private int $handledBytes = 0;

    public function setUp(): void
    {
        $type = new MessageType('example.work', 1);
        $this->message = new Message('registry-benchmark', $type, 'payload');
        $this->context = new ProcessingContext(new DeliveryMetadata('benchmark', 0, 1, CountAccuracy::Exact, new DateTimeImmutable('2026-11-27T00:00:00Z')), null);
        $this->registry = new HandlerRegistry([new HandlerRegistration($type, new CallbackHandler(function (Message $message): void {
            $this->handledBytes += strlen($message->body);
        }))]);
    }

    public function benchExactAliasDispatch(): void
    {
        $this->registry->handle($this->message, $this->context);
    }
}
