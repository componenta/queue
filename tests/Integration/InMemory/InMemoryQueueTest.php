<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Integration\InMemory;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\Exception\DeliveryLostException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\FailureNotFoundException;
use Componenta\Queue\Exception\FailureStateConflictException;
use Componenta\Queue\Exception\MessageConflictException;
use Componenta\Queue\Exception\ReceiverClosedException;
use Componenta\Queue\Exception\UnsupportedFeatureException;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\FailureStatus;
use Componenta\Queue\Headers;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\RawMessage;
use Componenta\Queue\Tests\Support\FrozenClock;
use DateTimeImmutable;

function message(string $id = 'message-1', string $body = 'payload', ?Headers $headers = null): Message
{
    return new Message($id, new MessageType('example.process', 1), $body, $headers ?? new Headers());
}

/** @return array{InMemoryQueue,FrozenClock,JsonMessageCodec} */
function queue(int $leaseMilliseconds = 1000, int $maxDeliveries = 3): array
{
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T12:00:00.123456+00:00'));
    $codec = new JsonMessageCodec(new MessageLimits(4096, 16384, 2048, 8));

    return [new InMemoryQueue('jobs', $codec, $clock, $leaseMilliseconds, $maxDeliveries), $clock, $codec];
}

it('shares queued work across independent receiver sessions and snapshots delivery metadata', function (): void {
    [$queue, $clock, $codec] = queue();
    $queue->publisher()->publish(message('first'));
    $queue->publisher()->publish(message('second'));
    $firstReceiver = $queue->receiver();
    $secondReceiver = $queue->receiver();

    $first = $firstReceiver->receive();
    $second = $secondReceiver->receive();

    expect($codec->decode($first->raw())->id)->toBe('first')
        ->and($codec->decode($second->raw())->id)->toBe('second')
        ->and($first->metadata()->source)->toBe('jobs')
        ->and($first->metadata()->retryCount)->toBe(0)
        ->and($first->metadata()->deliveryCount)->toBe(1)
        ->and($first->metadata()->countAccuracy)->toBe(CountAccuracy::Exact)
        ->and($first->metadata()->receivedAt)->toEqual($clock->now())
        ->and(fn () => $firstReceiver->receive())->toThrow(DeliveryStateException::class);

    $first->acknowledge();
    $second->acknowledge();

    expect($firstReceiver->receive())->toBeNull()->and($secondReceiver->receive())->toBeNull();
});

it('uses injected time for minimum eligibility and never executes work while publishing', function (): void {
    [$queue, $clock] = queue();
    $queue->publisher()->publish(message(), 500);
    $receiver = $queue->receiver();

    expect($receiver->receive(20))->toBeNull();
    $clock->advanceMilliseconds(499);
    expect($receiver->receive())->toBeNull();
    $clock->advanceMilliseconds(1);
    expect($receiver->receive())->toBeInstanceOf(DeliveryInterface::class);
});

it('retains publication deduplication after completion and compares original bytes and delay', function (): void {
    [$queue] = queue();
    $original = message(headers: new Headers(['x-b' => 'b', 'x-a' => 'a']));
    $queue->publisher()->publish($original);
    $queue->publisher()->publish(message(headers: new Headers(['x-a' => 'a', 'x-b' => 'b'])));
    $receiver = $queue->receiver();
    $receiver->receive()->acknowledge();

    $queue->publisher()->publish($original);

    expect($receiver->receive())->toBeNull()
        ->and(fn () => $queue->publisher()->publish(message(body: 'different')))->toThrow(MessageConflictException::class)
        ->and(fn () => $queue->publisher()->publish($original, 1))->toThrow(MessageConflictException::class);
});

it('does not share state between distinct queue objects with the same label', function (): void {
    [$first] = queue();
    [$second] = queue();
    $first->publisher()->publish(message());

    expect($second->receiver()->receive())->toBeNull()
        ->and($first->receiver()->receive())->toBeInstanceOf(DeliveryInterface::class);
});

it('invalidates settled delivery and retained renewal capabilities', function (): void {
    [$queue] = queue();
    $queue->publisher()->publish(message());
    $delivery = $queue->receiver()->receive();
    $lease = $delivery->lease();
    $delivery->acknowledge();

    expect($lease)->not->toBeInstanceOf(DeliveryInterface::class)
        ->and(fn () => $delivery->acknowledge())->toThrow(DeliveryStateException::class)
        ->and(fn () => $delivery->retry(0))->toThrow(DeliveryStateException::class)
        ->and(fn () => $delivery->fail(new FailureDetails(FailureReason::Handler)))->toThrow(DeliveryStateException::class)
        ->and(fn () => $lease->renew(1000))->toThrow(DeliveryStateException::class);
});

it('closes locally without acknowledging and recovers after the lease expires', function (): void {
    [$queue, $clock] = queue();
    $queue->publisher()->publish(message());
    $receiver = $queue->receiver();
    $delivery = $receiver->receive();
    $lease = $delivery->lease();
    $receiver->close();
    $receiver->close();

    expect(fn () => $receiver->receive())->toThrow(ReceiverClosedException::class)
        ->and(fn () => $delivery->acknowledge())->toThrow(DeliveryStateException::class)
        ->and(fn () => $lease->renew(1000))->toThrow(DeliveryStateException::class)
        ->and($queue->receiver()->receive())->toBeNull();

    $clock->advanceMilliseconds(1000);
    expect($queue->receiver()->receive()->metadata()->deliveryCount)->toBe(2);
});

it('rejects every mutation at strict lease expiry and terminates that session', function (string $operation): void {
    [$queue, $clock] = queue();
    $queue->publisher()->publish(message());
    $receiver = $queue->receiver();
    $delivery = $receiver->receive();
    $clock->advanceMilliseconds(1000);

    $mutate = match ($operation) {
        'acknowledge' => fn () => $delivery->acknowledge(),
        'retry' => fn () => $delivery->retry(0),
        'fail' => fn () => $delivery->fail(new FailureDetails(FailureReason::Handler)),
        'renew' => fn () => $delivery->lease()->renew(1000),
    };

    expect($mutate)->toThrow(DeliveryLostException::class)
        ->and(fn () => $receiver->receive())->toThrow(ReceiverClosedException::class)
        ->and($queue->receiver()->receive()->metadata()->deliveryCount)->toBe(2);
})->with(['acknowledge', 'retry', 'fail', 'renew']);

it('fences stale tokens after another session reclaims the work', function (): void {
    [$queue, $clock, $codec] = queue();
    $queue->publisher()->publish(message());
    $old = $queue->receiver()->receive();
    $oldLease = $old->lease();
    $clock->advanceMilliseconds(1000);
    $current = $queue->receiver()->receive();

    expect(fn () => $old->acknowledge())->toThrow(DeliveryLostException::class)
        ->and(fn () => $oldLease->renew(1000))->toThrow(DeliveryLostException::class)
        ->and($codec->decode($current->raw())->id)->toBe('message-1');

    $current->acknowledge();
    expect($queue->receiver()->receive())->toBeNull();
});

it('replaces the remaining lease from the current instant with microsecond precision', function (): void {
    [$queue, $clock] = queue();
    $queue->publisher()->publish(message());
    $delivery = $queue->receiver()->receive();
    $clock->advanceMilliseconds(600);

    $renewed = $delivery->lease()->renew(200);

    expect($renewed->expiresAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-27T12:00:00.923456+00:00');
    $clock->advanceMilliseconds(200);
    expect(fn () => $delivery->acknowledge())->toThrow(DeliveryLostException::class);
});

it('schedules retries without changing original bytes or counting them as native recovery', function (): void {
    [$queue, $clock] = queue();
    $original = message();
    $queue->publisher()->publish($original);
    $receiver = $queue->receiver();
    $first = $receiver->receive();
    $firstRaw = $first->raw();
    $firstMetadata = $first->metadata();
    $first->retry(200);
    $queue->publisher()->publish($original);

    expect($receiver->receive())->toBeNull()
        ->and(fn () => $queue->publisher()->publish($original, 200))->toThrow(MessageConflictException::class);
    $clock->advanceMilliseconds(200);
    $retried = $receiver->receive();

    expect($retried->raw())->toEqual($firstRaw)
        ->and($retried->metadata()->retryCount)->toBe(1)
        ->and($retried->metadata()->deliveryCount)->toBe(2)
        ->and($firstMetadata->retryCount)->toBe(0)
        ->and($firstMetadata->deliveryCount)->toBe(1);
});

it('rejects invalid operation arguments without losing the active delivery', function (): void {
    [$queue] = queue();
    expect(fn () => $queue->publisher()->publish(message(), -1))->toThrow(\InvalidArgumentException::class);
    $queue->publisher()->publish(message());
    $receiver = $queue->receiver();
    expect(fn () => $receiver->receive(-1))->toThrow(\InvalidArgumentException::class);
    $delivery = $receiver->receive();

    expect(fn () => $delivery->retry(-1))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $delivery->lease()->renew(0))->toThrow(\InvalidArgumentException::class);
    $delivery->acknowledge();
    expect($receiver->receive())->toBeNull();
});

it('quarantines exhausted crash recovery with its last delivery counters', function (): void {
    [$queue, $clock] = queue(maxDeliveries: 1);
    $queue->publisher()->publish(message());
    $receiver = $queue->receiver();
    $receiver->receive();
    $receiver->close();
    $clock->advanceMilliseconds(1000);

    expect($queue->receiver()->receive())->toBeNull();
    $page = $queue->failures()->page(new FailureQuery(10));
    $failed = $queue->failures()->find($page->items[0]->failureId);

    expect($page->items)->toHaveCount(1)
        ->and($failed->details->reason)->toBe(FailureReason::DeliveryLimit)
        ->and($failed->metadata->deliveryCount)->toBe(1)
        ->and($failed->metadata->retryCount)->toBe(0)
        ->and($failed->summary->status)->toBe(FailureStatus::Pending);
});

it('bounds maintenance work and continues recovery in later polls', function (): void {
    [$queue, $clock] = queue(maxDeliveries: 1);
    for ($index = 0; $index < 65; ++$index) {
        $queue->publisher()->publish(message('message-'.$index));
    }
    $claimed = 0;
    for ($index = 0; $index < 65; ++$index) {
        $receiver = $queue->receiver();
        if ($receiver->receive() !== null) {
            ++$claimed;
        }
        $receiver->close();
    }
    expect($claimed)->toBe(65);
    $clock->advanceMilliseconds(1000);

    $queue->receiver()->receive();
    $firstCount = count($queue->failures()->page(new FailureQuery(100))->items);
    expect($firstCount)->toBeGreaterThan(0)->toBeLessThan(65);
    $queue->receiver()->receive();
    expect($queue->failures()->page(new FailureQuery(100))->items)->toHaveCount(65);
});

it('provides scoped keyset pages and immutable failed-message snapshots', function (): void {
    [$queue] = queue();
    for ($index = 1; $index <= 3; ++$index) {
        $queue->publisher()->publish(message('message-'.$index, 'body-'.$index));
        $queue->receiver()->receive()->fail(new FailureDetails(FailureReason::Handler));
    }

    $first = $queue->failures()->page(new FailureQuery(1, status: FailureStatus::Pending));
    $second = $queue->failures()->page(new FailureQuery(2, $first->nextCursor, FailureStatus::Pending));
    $failed = $queue->failures()->find($first->items[0]->failureId);

    expect($first->items)->toHaveCount(1)
        ->and($first->items[0]->messageId)->toBe('message-1')
        ->and(array_map(fn ($item) => $item->messageId, $second->items))->toBe(['message-2', 'message-3'])
        ->and($second->nextCursor)->toBeNull()
        ->and(array_keys(get_object_vars($first->items[0])))->not->toContain('raw', 'body');

    $queue->failures()->discard($failed->summary->failureId);
    expect($failed->summary->status)->toBe(FailureStatus::Pending)
        ->and($queue->failures()->find($failed->summary->failureId)->summary->status)->toBe(FailureStatus::Discarded)
        ->and(fn () => $queue->failures()->page(new FailureQuery(1, $first->nextCursor)))
        ->toThrow(\InvalidArgumentException::class);

    [$other] = queue();
    expect(fn () => $other->failures()->page(new FailureQuery(1, $first->nextCursor, FailureStatus::Pending)))
        ->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $queue->failures()->page(new FailureQuery(1, 'invalid-cursor')))
        ->toThrow(\InvalidArgumentException::class);
});

it('redrives one new generation idempotently and never resurrects older failure history', function (): void {
    [$queue, $clock] = queue();
    $queue->publisher()->publish(message());
    $first = $queue->receiver()->receive();
    $originalRaw = $first->raw();
    $first->fail(new FailureDetails(FailureReason::Handler));
    $failureId = $queue->failures()->page(new FailureQuery(1))->items[0]->failureId;
    $queue->failures()->redrive($failureId, 100);
    $queue->failures()->redrive($failureId, 100);

    expect($queue->receiver()->receive())->toBeNull()
        ->and(fn () => $queue->failures()->redrive($failureId, 101))->toThrow(FailureStateConflictException::class)
        ->and(fn () => $queue->failures()->discard($failureId))->toThrow(FailureStateConflictException::class);

    $clock->advanceMilliseconds(100);
    $new = $queue->receiver()->receive();
    expect($new->raw())->toEqual($originalRaw)
        ->and($new->metadata()->retryCount)->toBe(0)
        ->and($new->metadata()->deliveryCount)->toBe(1);
    $new->fail(new FailureDetails(FailureReason::Handler));
    $queue->failures()->redrive($failureId, 100);

    expect($queue->receiver()->receive())->toBeNull()
        ->and($queue->failures()->page(new FailureQuery(10, status: FailureStatus::Pending))->items)->toHaveCount(1)
        ->and($queue->failures()->find($failureId)->summary->status)->toBe(FailureStatus::Redriven);
});

it('discards without allowing redrive or publication to restart retained work', function (): void {
    [$queue] = queue();
    $queue->publisher()->publish(message());
    $queue->receiver()->receive()->fail(new FailureDetails(FailureReason::Rejected));
    $failureId = $queue->failures()->page(new FailureQuery(1))->items[0]->failureId;
    $queue->failures()->discard($failureId);
    $queue->failures()->discard($failureId);
    $queue->publisher()->publish(message());

    expect($queue->receiver()->receive())->toBeNull()
        ->and(fn () => $queue->failures()->redrive($failureId))->toThrow(FailureStateConflictException::class)
        ->and($queue->failures()->find('missing'))->toBeNull()
        ->and(fn () => $queue->failures()->redrive('missing'))->toThrow(FailureNotFoundException::class)
        ->and(fn () => $queue->failures()->discard('missing'))->toThrow(FailureNotFoundException::class);
});

it('preserves malformed raw bytes through failure capture and redrive without decoding', function (): void {
    $corrupt = new RawMessage("{broken\xff", new Headers(['x-native' => 'preserved']));
    $codec = new class ($corrupt) implements MessageCodecInterface {
        public function __construct(private readonly RawMessage $raw)
        {
        }
        public function encode(Message $message): RawMessage
        {
            return $this->raw;
        }
        public function decode(RawMessage $message): Message
        {
            throw new \LogicException('Transport must not decode.');
        }
    };
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-27T12:00:00+00:00'));
    $queue = new InMemoryQueue('jobs', $codec, $clock, 1000, 3);
    $queue->publisher()->publish(message());
    $queue->receiver()->receive()->fail(new FailureDetails(FailureReason::Decode));
    $id = $queue->failures()->page(new FailureQuery(1))->items[0]->failureId;

    expect($queue->failures()->find($id)->raw)->toEqual($corrupt);
    $queue->failures()->redrive($id);
    expect($queue->receiver()->receive()->raw())->toEqual($corrupt);
});

it('rejects unusable adapter configuration', function (string $name, int $lease, int $deliveries): void {
    [, $clock, $codec] = queue();

    expect(fn () => new InMemoryQueue($name, $codec, $clock, $lease, $deliveries))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'empty source' => ['', 1000, 3],
    'control source' => ["jobs\n", 1000, 3],
    'invalid UTF-8 source' => ["jobs\xff", 1000, 3],
    'zero lease' => ['jobs', 0, 3],
    'negative lease' => ['jobs', -1, 3],
    'zero delivery budget' => ['jobs', 1000, 0],
]);

it('rejects timestamp overflow before reserving the message', function (): void {
    [, , $codec] = queue();
    $clock = new FrozenClock(new DateTimeImmutable('@'.PHP_INT_MAX));
    $queue = new InMemoryQueue('jobs', $codec, $clock, 1000, 3);
    $queue->publisher()->publish(message());
    $receiver = $queue->receiver();

    expect(fn () => $receiver->receive())->toThrow(UnsupportedFeatureException::class);
    $clock->set(new DateTimeImmutable('2026-11-27T12:00:00+00:00'));
    $queue->publisher()->publish(message('second'));
    expect($receiver->receive()->metadata()->deliveryCount)->toBe(1);
});
