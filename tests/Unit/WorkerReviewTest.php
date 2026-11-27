<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit;

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryInterface;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\Exception\DecodingException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\IndeterminateOperationException;
use Componenta\Queue\Exception\ProcessingException;
use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\LeaseInterface;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingContext;
use Componenta\Queue\RawMessage;
use Componenta\Queue\ReceiverInterface;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Tests\Support\WorkerProbe;
use Componenta\Queue\Worker;
use DateTimeImmutable;
use RuntimeException;

it('does not classify a raw accessor failure as malformed message data', function (): void {
    $failure = new DecodingException('Adapter failed to expose its raw snapshot.');
    $probe = new class ($failure) implements ReceiverInterface, DeliveryInterface {
        public array $calls = [];

        public function __construct(private readonly DecodingException $failure)
        {
        }

        public function receive(int $waitMilliseconds = 0): ?DeliveryInterface
        {
            $this->calls[] = 'receive';

            return $this;
        }

        public function close(): void
        {
            $this->calls[] = 'close';
        }

        public function raw(): RawMessage
        {
            throw $this->failure;
        }

        public function metadata(): DeliveryMetadata
        {
            return new DeliveryMetadata('jobs', 0, 1, CountAccuracy::Exact, new DateTimeImmutable('2026-01-01T00:00:00Z'));
        }

        public function lease(): ?LeaseInterface
        {
            return null;
        }

        public function acknowledge(): void
        {
            $this->calls[] = 'acknowledge';
        }

        public function retry(int $delayMilliseconds): void
        {
            $this->calls[] = 'retry';
        }

        public function fail(FailureDetails $failure): void
        {
            $this->calls[] = 'fail';
        }
    };
    $handlerCalls = 0;
    $worker = new Worker(
        $probe,
        new JsonMessageCodec(new MessageLimits(1_024, 4_096, 1_024, 8)),
        new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$handlerCalls): void {
            ++$handlerCalls;
        }),
        new NoRetryPolicy(),
    );
    $caught = null;

    try {
        $worker->processOne();
    } catch (DecodingException $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure)
        ->and($handlerCalls)->toBe(0)
        ->and($probe->calls)->toBe(['receive', 'close']);
});

it('preserves a receive or acknowledgement outcome when receiver cleanup also fails', function (string $stage): void {
    $codec = new JsonMessageCodec(new MessageLimits(1_024, 4_096, 1_024, 8));
    $probe = new WorkerProbe($codec->encode(new Message('message-1', new MessageType('job.run', 1), '{}')));
    $infrastructure = new IndeterminateOperationException('Outcome unknown.');
    $closeFailure = new RuntimeException('Close failed.');
    $probe->closeError = $closeFailure;
    if ($stage === 'receive') {
        $probe->receiveError = $infrastructure;
    } else {
        $probe->settleError = $infrastructure;
    }
    $escapedLease = null;
    $worker = new Worker($probe, $codec, new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$escapedLease): void {
        $escapedLease = $context->lease;
    }), new NoRetryPolicy());
    $caught = null;

    try {
        $worker->processOne();
    } catch (ProcessingException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(ProcessingException::class)
        ->and($caught->processingError)->toBeNull()
        ->and($caught->infrastructureError)->toBe($infrastructure)
        ->and($caught->closeError)->toBe($closeFailure)
        ->and($caught->getPrevious())->toBe($infrastructure);

    if ($stage === 'acknowledge') {
        expect($escapedLease)->toBeInstanceOf(LeaseInterface::class);
        expect(fn () => $escapedLease->renew(100))->toThrow(DeliveryStateException::class);
    }

    expect($probe->calls)->toBe($stage === 'receive'
        ? [['receive', 0], ['close']]
        : [['receive', 0], ['acknowledge'], ['close']]);
})->with(['receive', 'acknowledge']);

it('preserves decode, failed capture and cleanup failures without acknowledging malformed work', function (): void {
    $decodeFailure = new DecodingException('Malformed envelope.');
    $captureFailure = new TransportException('Failure storage unavailable.');
    $closeFailure = new RuntimeException('Close failed.');
    $codec = new class ($decodeFailure) implements MessageCodecInterface {
        public function __construct(private readonly DecodingException $failure)
        {
        }

        public function encode(Message $message): RawMessage
        {
            return new RawMessage('unused');
        }

        public function decode(RawMessage $message): Message
        {
            throw $this->failure;
        }
    };
    $probe = new WorkerProbe(new RawMessage('malformed wire'));
    $probe->settleError = $captureFailure;
    $probe->closeError = $closeFailure;
    $handlerCalls = 0;
    $worker = new Worker($probe, $codec, new CallbackHandler(static function (Message $message, ProcessingContext $context) use (&$handlerCalls): void {
        ++$handlerCalls;
    }), new NoRetryPolicy());
    $caught = null;

    try {
        $worker->processOne();
    } catch (ProcessingException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(ProcessingException::class)
        ->and($caught->processingError)->toBe($decodeFailure)
        ->and($caught->infrastructureError)->toBe($captureFailure)
        ->and($caught->closeError)->toBe($closeFailure)
        ->and($caught->getPrevious())->toBe($captureFailure)
        ->and($handlerCalls)->toBe(0)
        ->and($probe->calls)->toBe([['receive', 0], ['fail', 'decode'], ['close']]);
});
