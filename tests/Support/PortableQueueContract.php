<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Support;

use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\FailureStateConflictException;
use Componenta\Queue\Exception\MessageConflictException;
use Componenta\Queue\Exception\ReceiverClosedException;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\FailureStatus;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingResult;
use Componenta\Queue\RawMessage;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Worker;
use InvalidArgumentException;
use Throwable;

/** The same public behavior runs against memory and a real PostgreSQL database. */
trait PortableQueueContract
{
    abstract protected function contractQueue(string $name = 'jobs', ?MessageCodecInterface $codec = null): QueueHarness;

    private function contractMessage(string $id = 'm1', string $body = "\0\xffpayload"): Message
    {
        return new Message($id, new MessageType('test.work', 1), $body, new Headers(['content-type' => 'application/octet-stream']));
    }

    private function contractDelivery(QueueHarness $queue): DeliveryInterface
    {
        $delivery = $queue->receiver()->receive();
        self::assertNotNull($delivery, 'A ready control message must be acquired.');
        return $delivery;
    }

    private function contractThrows(string $class, callable $operation): void
    {
        $caught = null;
        try {
            $operation();
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertInstanceOf($class, $caught);
    }

    public function testPortableBinaryMessageRoundTripAndAcknowledgment(): void
    {
        $queue = $this->contractQueue();
        $message = $this->contractMessage();
        $queue->publisher->publish($message);
        $receiver = $queue->receiver();
        $delivery = $receiver->receive();
        self::assertNotNull($delivery);
        self::assertEquals($message, $queue->codec->decode($delivery->raw()));
        self::assertSame(0, $delivery->metadata()->retryCount);
        self::assertSame(1, $delivery->metadata()->deliveryCount);
        $delivery->acknowledge();
        self::assertNull($receiver->receive());
        $receiver->close();
    }

    public function testPortablePublicationIdentityAndRetainedCompletion(): void
    {
        $queue = $this->contractQueue();
        $message = $this->contractMessage();
        $queue->publisher->publish($message);
        $queue->publisher->publish($message);
        $this->contractThrows(MessageConflictException::class, fn () => $queue->publisher->publish($this->contractMessage(body: 'different')));
        $this->contractThrows(MessageConflictException::class, fn () => $queue->publisher->publish($message, 1));
        $this->contractDelivery($queue)->acknowledge();
        $queue->publisher->publish($message);
        self::assertNull($queue->receiver()->receive());
    }

    public function testPortableValidationPrecedesPublicationAndSettlement(): void
    {
        $queue = $this->contractQueue();
        $this->contractThrows(InvalidArgumentException::class, fn () => $queue->publisher->publish($this->contractMessage(), -1));
        self::assertNull($queue->receiver()->receive());
        $queue->publisher->publish($this->contractMessage());
        $delivery = $this->contractDelivery($queue);
        $this->contractThrows(InvalidArgumentException::class, fn () => $delivery->retry(-1));
        $this->contractThrows(InvalidArgumentException::class, fn () => $delivery->lease()?->renew(0));
        $delivery->acknowledge();
        self::assertNull($queue->receiver()->receive());
    }

    public function testPortableSessionHasOneOutstandingDelivery(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage('m1'));
        $queue->publisher->publish($this->contractMessage('m2'));
        $receiver = $queue->receiver();
        $first = $receiver->receive();
        self::assertNotNull($first);
        $this->contractThrows(DeliveryStateException::class, fn () => $receiver->receive());
        $second = $this->contractDelivery($queue);
        self::assertNotSame($queue->codec->decode($first->raw())->id, $queue->codec->decode($second->raw())->id);
        $first->acknowledge();
        $second->acknowledge();
        self::assertNull($receiver->receive());
    }

    public function testPortableSettledCapabilitiesCannotBeReused(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage());
        $delivery = $this->contractDelivery($queue);
        $lease = $delivery->lease();
        self::assertNotNull($lease);
        self::assertNotInstanceOf(DeliveryInterface::class, $lease);
        $delivery->acknowledge();
        $this->contractThrows(DeliveryStateException::class, $delivery->acknowledge(...));
        $this->contractThrows(DeliveryStateException::class, fn () => $delivery->retry(0));
        $this->contractThrows(DeliveryStateException::class, fn () => $delivery->fail(new FailureDetails(FailureReason::Handler)));
        $this->contractThrows(DeliveryStateException::class, fn () => $lease->renew(1_000));
        self::assertSame(1, $delivery->metadata()->deliveryCount);
    }

    public function testPortableCloseInvalidatesCapabilitiesWithoutAcknowledging(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage());
        $receiver = $queue->receiver();
        $delivery = $receiver->receive();
        self::assertNotNull($delivery);
        $receiver->close();
        $receiver->close();
        $this->contractThrows(ReceiverClosedException::class, fn () => $receiver->receive());
        $this->contractThrows(DeliveryStateException::class, $delivery->acknowledge(...));
        $this->contractThrows(DeliveryStateException::class, fn () => $delivery->lease()?->renew(1_000));
        self::assertEquals($this->contractMessage(), $queue->codec->decode($delivery->raw()));
    }

    public function testPortableRetryPreservesMessageAndCountsCommittedReservations(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage());
        $first = $this->contractDelivery($queue);
        $raw = $first->raw();
        $first->retry(0);
        $second = $this->contractDelivery($queue);
        self::assertEquals($raw, $second->raw());
        self::assertSame(1, $second->metadata()->retryCount);
        self::assertSame(2, $second->metadata()->deliveryCount);
        $second->acknowledge();
    }

    public function testPortableFailureSnapshotAndRedriveIdempotence(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage());
        $first = $this->contractDelivery($queue);
        $first->retry(0);
        $failed = $this->contractDelivery($queue);
        $raw = $failed->raw();
        $metadata = $failed->metadata();
        $details = new FailureDetails(FailureReason::Handler, 'DomainException', 'Explicit diagnostic.');
        $failed->fail($details);
        $page = $queue->failures->page(new FailureQuery(1, status: FailureStatus::Pending));
        self::assertCount(1, $page->items);
        $id = $page->items[0]->failureId;
        $snapshot = $queue->failures->find($id);
        self::assertNotNull($snapshot);
        self::assertEquals($raw, $snapshot->raw);
        self::assertEquals($metadata, $snapshot->metadata);
        self::assertEquals($details, $snapshot->details);
        self::assertNull($queue->failures->find('not-a-failure'));
        $queue->failures->redrive($id);
        $queue->failures->redrive($id);
        $this->contractThrows(FailureStateConflictException::class, fn () => $queue->failures->redrive($id, 10));
        $this->contractThrows(FailureStateConflictException::class, fn () => $queue->failures->discard($id));
        $redriven = $this->contractDelivery($queue);
        self::assertEquals($raw, $redriven->raw());
        self::assertSame(0, $redriven->metadata()->retryCount);
        self::assertSame(1, $redriven->metadata()->deliveryCount);
        self::assertSame(FailureStatus::Redriven, $queue->failures->find($id)->summary->status);
        $redriven->acknowledge();
        self::assertNull($queue->receiver()->receive());
    }

    public function testPortableDiscardIsIdempotentAndCannotBeRedriven(): void
    {
        $queue = $this->contractQueue();
        $queue->publisher->publish($this->contractMessage());
        $this->contractDelivery($queue)->fail(new FailureDetails(FailureReason::Rejected));
        $id = $queue->failures->page(new FailureQuery(1))->items[0]->failureId;
        $queue->failures->discard($id);
        $queue->failures->discard($id);
        self::assertSame(FailureStatus::Discarded, $queue->failures->find($id)->summary->status);
        $this->contractThrows(FailureStateConflictException::class, fn () => $queue->failures->redrive($id));
        self::assertNull($queue->receiver()->receive());
    }

    public function testPortablePaginationIsBoundedOrderedAndScoped(): void
    {
        $queue = $this->contractQueue();
        foreach (['m1', 'm2', 'm3'] as $id) {
            $queue->publisher->publish($this->contractMessage($id));
            $this->contractDelivery($queue)->fail(new FailureDetails(FailureReason::Handler));
        }
        $first = $queue->failures->page(new FailureQuery(2));
        self::assertSame(['m1', 'm2'], array_map(fn ($summary) => $summary->messageId, $first->items));
        self::assertNotNull($first->nextCursor);
        $last = $queue->failures->page(new FailureQuery(2, $first->nextCursor));
        self::assertSame(['m3'], array_map(fn ($summary) => $summary->messageId, $last->items));
        self::assertNull($last->nextCursor);
        $this->contractThrows(InvalidArgumentException::class, fn () => $queue->failures->page(new FailureQuery(2, $first->nextCursor, FailureStatus::Pending)));
        $other = $this->contractQueue('another');
        $this->contractThrows(InvalidArgumentException::class, fn () => $other->failures->page(new FailureQuery(2, $first->nextCursor)));
        $this->contractThrows(InvalidArgumentException::class, fn () => $queue->failures->page(new FailureQuery(2, 'malformed')));
    }

    public function testPortableMalformedEnvelopeIsRetainedWithoutHandlerExecution(): void
    {
        $raw = new RawMessage("bad\0wire", new Headers(['content-type' => 'invalid']));
        $codec = new class ($raw) implements MessageCodecInterface {
            public function __construct(private RawMessage $raw)
            {
            }
            public function encode(Message $message): RawMessage
            {
                return $this->raw;
            }
            public function decode(RawMessage $message): Message
            {
                throw new DecodingException('Poison envelope.');
            }
        };
        $queue = $this->contractQueue(codec: $codec);
        $queue->publisher->publish($this->contractMessage());
        $calls = 0;
        $worker = new Worker($queue->receiver(), $codec, new CallbackHandler(function () use (&$calls): void {
            ++$calls;
        }), new NoRetryPolicy());
        self::assertSame(ProcessingResult::Failed, $worker->processOne());
        self::assertSame(0, $calls);
        $summary = $queue->failures->page(new FailureQuery(1))->items[0];
        self::assertSame(FailureReason::Decode, $summary->reason);
        self::assertEquals($raw, $queue->failures->find($summary->failureId)->raw);
    }
}
