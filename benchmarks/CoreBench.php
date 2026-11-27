<?php

declare(strict_types=1);

namespace Componenta\Queue\Benchmarks;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\RawMessage;
use Componenta\Queue\Retry\ExponentialBackoff;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(100)]
#[Bench\Iterations(5)]
#[Bench\ParamProviders('payloads')]
final class CoreBench
{
    private JsonMessageCodec $codec;
    private Message $message;
    private RawMessage $raw;
    private ExponentialBackoff $backoff;
    private mixed $result;

    public function payloads(): iterable
    {
        yield 'small' => ['bytes' => 256];
        yield 'large' => ['bytes' => 65_536];
    }

    public function setUp(array $params): void
    {
        $this->codec = new JsonMessageCodec(new MessageLimits(1_000_000, 2_000_000, 4096, 8));
        $this->message = new Message('benchmark-message', new MessageType('example.work', 1), str_repeat('x', $params['bytes']), new Headers(['content-type' => 'application/octet-stream']));
        $this->raw = $this->codec->encode($this->message);
        $this->backoff = new ExponentialBackoff(100, 30_000, randomInt: static fn (int $min, int $max): int => $max);
    }

    public function benchEncode(): void
    {
        $this->result = $this->codec->encode($this->message);
    }
    public function benchDecode(): void
    {
        $this->result = $this->codec->decode($this->raw);
    }
    public function benchValidateHeaders(): void
    {
        $this->result = new Headers(['content-type' => 'application/octet-stream', 'correlation-id' => 'test-operation']);
    }
    public function benchCappedBackoff(): void
    {
        $this->result = $this->backoff->delayFor(20);
    }
}
