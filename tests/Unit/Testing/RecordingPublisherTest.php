<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit\Testing;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Exception\InvalidMessageException;
use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\OperationOutcome;
use Componenta\Queue\RawMessage;
use Componenta\Queue\Testing\RecordedPublication;
use Componenta\Queue\Testing\RecordingPublisher;
use Error;
use InvalidArgumentException;
use RuntimeException;

function publicationMessage(string $id = 'message-1', string $body = '{}'): Message
{
    return new Message($id, new MessageType('invoice.generate', 1), $body);
}

function publicationCodec(): JsonMessageCodec
{
    return new JsonMessageCodec(new MessageLimits(1_024, 4_096, 1_024, 8));
}

it('records messages in publication order and keeps their requested millisecond delays', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 2);
    $first = publicationMessage();
    $second = publicationMessage('message-2', "\x00\xff");

    $publisher->publish($first);
    $publisher->publish($second, delayMilliseconds: 15_000);
    $records = $publisher->records();

    expect($records)->toHaveCount(2)
        ->and($records[0]->message)->toBe($first)
        ->and($records[0]->delayMilliseconds)->toBe(0)
        ->and($records[1]->message)->toBe($second)
        ->and($records[1]->delayMilliseconds)->toBe(15_000);
});

it('validates through the configured codec without decoding or executing a message', function (): void {
    $codec = new class () implements MessageCodecInterface {
        /** @var list<Message> */
        public array $encoded = [];
        public int $decodes = 0;

        public function encode(Message $message): RawMessage
        {
            $this->encoded[] = $message;

            return new RawMessage('encoded');
        }

        public function decode(RawMessage $message): Message
        {
            ++$this->decodes;

            throw new RuntimeException('A recording publisher cannot consume.');
        }
    };
    $publisher = new RecordingPublisher($codec, 1);
    $message = publicationMessage();

    $publisher->publish($message);

    expect($codec->encoded)->toBe([$message])
        ->and($codec->decodes)->toBe(0)
        ->and($publisher->records())->toHaveCount(1);
});

it('records nothing when the codec rejects the publication', function (): void {
    $publisher = new RecordingPublisher(new JsonMessageCodec(new MessageLimits(1, 4_096, 1_024, 8)), 1);

    expect(fn () => $publisher->publish(publicationMessage(body: 'too large')))->toThrow(InvalidMessageException::class)
        ->and($publisher->records())->toBe([]);
});

it('rejects negative delays without recording a publication', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 1);

    expect(fn () => $publisher->publish(publicationMessage(), -1))->toThrow(InvalidArgumentException::class)
        ->and($publisher->records())->toBe([]);
});

it('requires a positive explicit recording capacity', function (int $capacity): void {
    expect(fn () => new RecordingPublisher(publicationCodec(), $capacity))->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('reports capacity exhaustion as a no-effect failure and retains accepted records', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 1);
    $first = publicationMessage();
    $publisher->publish($first);
    $caught = null;

    try {
        $publisher->publish(publicationMessage('message-2'));
    } catch (TransportException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(TransportException::class)
        ->and($caught->outcome)->toBe(OperationOutcome::NotApplied)
        ->and($publisher->records())->toHaveCount(1)
        ->and($publisher->records()[0]->message)->toBe($first);
});

it('returns array snapshots that cannot mutate the retained record collection', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 2);
    $publisher->publish(publicationMessage());
    $earlierSnapshot = $publisher->records();

    $publisher->publish(publicationMessage('message-2'));
    $changedSnapshot = $publisher->records();
    array_pop($changedSnapshot);

    expect($earlierSnapshot)->toHaveCount(1)
        ->and($changedSnapshot)->toHaveCount(1)
        ->and($publisher->records())->toHaveCount(2);
});

it('does not permit recorded metadata to be changed through a returned record', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 1);
    $publisher->publish(publicationMessage(), 50);
    $record = $publisher->records()[0];

    expect(function () use ($record): void {
        $record->delayMilliseconds = 0;
    })->toThrow(Error::class)
        ->and($publisher->records()[0]->delayMilliseconds)->toBe(50);
});

it('records repeated publication intents without implying transport deduplication', function (): void {
    $publisher = new RecordingPublisher(publicationCodec(), 2);
    $message = publicationMessage();

    $publisher->publish($message);
    $publisher->publish($message);

    expect($publisher->records())->toHaveCount(2);
});

it('preserves an unexpected codec failure without adding a record', function (): void {
    $failure = new RuntimeException('Codec failed.');
    $codec = new class ($failure) implements MessageCodecInterface {
        public function __construct(private readonly RuntimeException $failure)
        {
        }

        public function encode(Message $message): RawMessage
        {
            throw $this->failure;
        }

        public function decode(RawMessage $message): Message
        {
            throw new RuntimeException('Unused.');
        }
    };
    $publisher = new RecordingPublisher($codec, 1);
    $caught = null;

    try {
        $publisher->publish(publicationMessage());
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure)
        ->and($publisher->records())->toBe([]);
});

it('prevents constructing recorded metadata with a negative delay', function (): void {
    expect(fn () => new RecordedPublication(publicationMessage(), -1))->toThrow(InvalidArgumentException::class);
});
