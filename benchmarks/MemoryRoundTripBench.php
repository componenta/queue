<?php

declare(strict_types=1);

namespace Componenta\Queue\Benchmarks;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Handler\HandlerRegistration;
use Componenta\Queue\Handler\HandlerRegistry;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingResult;
use Componenta\Queue\PublisherInterface;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Worker;
use DateTimeImmutable;
use PhpBench\Attributes as Bench;
use Psr\Clock\ClockInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(100)]
#[Bench\Iterations(5)]
final class MemoryRoundTripBench
{
    private PublisherInterface $publisher;
    private Worker $worker;
    private int $sequence = 0;
    private int $handledBytes = 0;
    private ProcessingResult $result;
    private MessageType $type;

    public function setUp(): void
    {
        $codec = new JsonMessageCodec(new MessageLimits(1024, 4096, 1024, 8));
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-27T00:00:00Z');
            }
        };
        $queue = new InMemoryQueue('benchmark', $codec, $clock, 10_000, 3);
        $this->type = new MessageType('example.work', 1);
        $handler = new HandlerRegistry([new HandlerRegistration($this->type, new CallbackHandler(function (Message $message): void {
            $this->handledBytes += strlen($message->body);
        }))]);
        $this->publisher = $queue->publisher();
        $this->worker = new Worker($queue->receiver(), $codec, $handler, new NoRetryPolicy());
        $this->sequence = $this->handledBytes = 0;
    }

    /** Includes ID/message creation, encoding, publishing, dispatch and settlement. */
    public function benchPublishAndProcess(): void
    {
        $this->publisher->publish(new Message('bench-' . ++$this->sequence, $this->type, str_repeat('x', 256)));
        $this->result = $this->worker->processOne();
        if ($this->result !== ProcessingResult::Acknowledged) {
            throw new \RuntimeException('Benchmark must perform real work on every revolution.');
        }
    }
}
