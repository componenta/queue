<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Integration\InMemory;

use Closure;
use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\Exception\UnsupportedFeatureException;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\OperationOutcome;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

it('does not capture exhausted work when a later candidate cannot be prepared', function (string $failureKind): void {
    $clock = new class () implements ClockInterface {
        public DateTimeImmutable $instant;
        /** @var (Closure():DateTimeImmutable)|null */
        public ?Closure $read = null;

        public function __construct()
        {
            $this->instant = new DateTimeImmutable('2026-11-27T12:00:00.123456+00:00');
        }

        public function now(): DateTimeImmutable
        {
            return $this->read === null ? $this->instant : ($this->read)();
        }
    };
    $codec = new JsonMessageCodec(new MessageLimits(1024, 8192, 1024, 8));
    $queue = new InMemoryQueue('jobs', $codec, $clock, 1000, 1);
    $queue->publisher()->publish(new Message('exhausted', new MessageType('poll.test', 1), 'original'));
    $previous = $queue->receiver();
    $previous->receive();
    $previous->close();
    $queue->publisher()->publish(new Message('ready', new MessageType('poll.test', 1), 'next'));
    $clock->instant = $clock->instant->modify('+1 second');

    $failure = new RuntimeException('Clock temporarily unavailable.');
    $reads = 0;
    $clock->read = static function () use ($clock, $failureKind, $failure, &$reads): DateTimeImmutable {
        if (++$reads === 2) {
            if ($failureKind === 'clock') {
                throw $failure;
            }
            return new DateTimeImmutable('@'.PHP_INT_MAX);
        }
        return $clock->instant;
    };
    $receiver = $queue->receiver();
    $caught = null;
    try {
        $receiver->receive();
    } catch (Throwable $error) {
        $caught = $error;
    } finally {
        $clock->read = null;
    }

    if ($failureKind === 'clock') {
        expect($caught)->toBeInstanceOf(TransportException::class)
            ->and($caught->outcome)->toBe(OperationOutcome::NotApplied)
            ->and($caught->getPrevious())->toBe($failure);
    } else {
        expect($caught)->toBeInstanceOf(UnsupportedFeatureException::class);
    }
    expect($queue->failures()->page(new FailureQuery(10))->items)->toBe([]);

    $delivery = $receiver->receive();
    $failed = $queue->failures()->page(new FailureQuery(10))->items;
    expect($codec->decode($delivery->raw())->id)->toBe('ready')
        ->and($delivery->metadata()->deliveryCount)->toBe(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->messageId)->toBe('exhausted')
        ->and($failed[0]->reason)->toBe(FailureReason::DeliveryLimit);
    $delivery->acknowledge();
    expect($receiver->receive())->toBeNull();
})->with(['clock', 'timestamp overflow']);
