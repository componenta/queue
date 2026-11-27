<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Conformance;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\Tests\Support\PortableQueueContract;
use Componenta\Queue\Tests\Support\QueueHarness;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class InMemoryContractTest extends TestCase
{
    use PortableQueueContract;

    protected function contractQueue(string $name = 'jobs', ?MessageCodecInterface $codec = null): QueueHarness
    {
        $codec ??= new JsonMessageCodec(new MessageLimits(1024, 4096, 1024, 8));
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-27T00:00:00Z');
            }
        };
        $queue = new InMemoryQueue($name, $codec, $clock, 5_000, 10);
        return new QueueHarness($queue->publisher(), $queue->receiver(...), $queue->failures(), $codec);
    }
}
