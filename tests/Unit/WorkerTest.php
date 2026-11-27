<?php

declare(strict_types=1);

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\Exception\DeliveryLostException;
use Componenta\Queue\Exception\DeliveryStateException;
use Componenta\Queue\Exception\IndeterminateOperationException;
use Componenta\Queue\Exception\PermanentMessageException;
use Componenta\Queue\Exception\ProcessingException;
use Componenta\Queue\Exception\ReceiverClosedException;
use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\FailureContext;
use Componenta\Queue\FailureReason;
use Componenta\Queue\Handler\CallbackHandler;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Componenta\Queue\ProcessingContext;
use Componenta\Queue\ProcessingResult;
use Componenta\Queue\RawMessage;
use Componenta\Queue\Retry\Fail;
use Componenta\Queue\Retry\RetryAfter;
use Componenta\Queue\RetryPolicyInterface;
use Componenta\Queue\Tests\Support\WorkerProbe;
use Componenta\Queue\Worker;
use Psr\Log\AbstractLogger;

function workerCodec(): JsonMessageCodec
{
    return new JsonMessageCodec(new MessageLimits(1024, 4096, 1024, 8));
}
function workerProbe(): WorkerProbe
{
    return new WorkerProbe(workerCodec()->encode(new Message('m1', new MessageType('job.run', 1), 'body')));
}
function workerPolicy(callable $callback): RetryPolicyInterface
{
    return new class ($callback) implements RetryPolicyInterface {
        public function __construct(private mixed $callback)
        {
        }
        public function decide(FailureContext $failure): RetryAfter|Fail
        {
            return ($this->callback)($failure);
        }
    };
}
function workerNeverRetry(): RetryPolicyInterface
{
    return workerPolicy(static function (): never {
        throw new LogicException('Policy must not be called.');
    });
}

it('acknowledges only after a successful handler and limits handler authority', function (): void {
    $probe = workerProbe();
    $seen = null;
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(function (Message $message, ProcessingContext $context) use ($probe, &$seen): void {
        $probe->calls[] = ['handle'];
        $seen = [$message, $context];
    }), workerNeverRetry());
    expect($worker->processOne(125))->toBe(ProcessingResult::Acknowledged)
        ->and($probe->calls)->toBe([['receive', 125], ['handle'], ['acknowledge']])
        ->and($seen[0]->id)->toBe('m1')
        ->and($seen[1]->metadata)->toBe($probe->info)
        ->and($seen[1]->lease)->toBeInstanceOf(Componenta\Queue\LeaseInterface::class)
        ->not->toBeInstanceOf(Componenta\Queue\DeliveryInterface::class);
    expect(fn () => $seen[1]->lease->renew(10))->toThrow(DeliveryStateException::class);
    expect($probe->calls)->toHaveCount(3);
});

it('returns idle without invoking downstream processing', function (): void {
    $probe = workerProbe();
    $probe->empty = true;
    $calls = 0;
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(function () use (&$calls): void {
        ++$calls;
    }), workerNeverRetry());
    expect($worker->processOne())->toBe(ProcessingResult::Idle)->and($calls)->toBe(0)->and($probe->calls)->toBe([['receive', 0]]);
});

it('records malformed raw messages before any handler invocation', function (): void {
    $probe = new WorkerProbe(new RawMessage('{broken'));
    $calls = 0;
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(function () use (&$calls): void {
        ++$calls;
    }), workerNeverRetry());
    expect($worker->processOne())->toBe(ProcessingResult::Failed)->and($calls)->toBe(0)
        ->and($probe->calls)->toBe([['receive', 0], ['fail', 'decode']])
        ->and($probe->failure->description)->toBeNull();
});

it('executes the single disposition selected for an ordinary handler error', function (string $kind): void {
    $probe = workerProbe();
    $error = new RuntimeException('a secret that must not be captured');
    $seen = null;
    $policy = workerPolicy(function (FailureContext $context) use (&$seen, $kind): RetryAfter|Fail {
        $seen = $context;
        return $kind === 'retry' ? new RetryAfter(350) : new Fail();
    });
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function () use ($error): never {
        throw $error;
    }), $policy);
    expect($worker->processOne())->toBe($kind === 'retry' ? ProcessingResult::RetryScheduled : ProcessingResult::Failed)
        ->and($seen->error)->toBe($error)->and($seen->message->id)->toBe('m1')->and($seen->metadata)->toBe($probe->info)
        ->and($probe->calls)->toBe([['receive', 0], $kind === 'retry' ? ['retry', 350] : ['fail', 'handler']]);
    if ($kind === 'fail') {
        expect($probe->failure->exceptionType)->toBe(RuntimeException::class)->and($probe->failure->description)->toBeNull();
    }
})->with(['retry', 'fail']);

it('bypasses policy on an explicit permanent rejection', function (): void {
    $probe = workerProbe();
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function (): never {
        throw new PermanentMessageException('reject');
    }), workerNeverRetry());
    expect($worker->processOne())->toBe(ProcessingResult::Failed)->and($probe->failure->reason)->toBe(FailureReason::Rejected);
});

it('contains logger failures after a successful disposition', function (bool $handlerFails): void {
    $probe = workerProbe();
    $logger = new class () extends AbstractLogger {
        public int $calls = 0;
        public function log($level, Stringable|string $message, array $context = []): void
        {
            ++$this->calls;
            throw new RuntimeException('logging');
        }
    };
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function () use ($handlerFails): void {
        if ($handlerFails) {
            throw new RuntimeException('handler');
        }
    }), workerPolicy(static fn () => new Fail()), $logger);
    expect($worker->processOne())->toBe($handlerFails ? ProcessingResult::Failed : ProcessingResult::Acknowledged)
        ->and($logger->calls)->toBeGreaterThan(0)
        ->and($probe->calls)->toBe([['receive', 0], $handlerFails ? ['fail', 'handler'] : ['acknowledge']]);
})->with([false, true]);

it('closes and permanently stops a worker after receive or acknowledgement infrastructure failure', function (string $at): void {
    $probe = workerProbe();
    $infra = new IndeterminateOperationException('unknown result');
    if ($at === 'receive') {
        $probe->receiveError = $infra;
    } else {
        $probe->settleError = $infra;
    }
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function (): void {
    }), workerNeverRetry());
    $caught = null;
    try {
        $worker->processOne();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBe($infra)->and($probe->calls)->toBe($at === 'receive' ? [['receive', 0], ['close']] : [['receive', 0], ['acknowledge'], ['close']]);
    expect(fn () => $worker->processOne())->toThrow(ReceiverClosedException::class);
})->with(['receive', 'acknowledge']);

it('preserves processing and infrastructure errors when retry or failure capture fails', function (string $kind): void {
    $probe = workerProbe();
    $original = new RuntimeException('handler');
    $infra = new TransportException('not applied');
    $probe->settleError = $infra;
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function () use ($original): never {
        throw $original;
    }), workerPolicy(static fn () => $kind === 'retry' ? new RetryAfter(12) : new Fail()));
    $caught = null;
    try {
        $worker->processOne();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf(ProcessingException::class)->and($caught->processingError)->toBe($original)
        ->and($caught->infrastructureError)->toBe($infra)->and($caught->getPrevious())->toBe($infra)
        ->and($probe->calls)->toBe([['receive', 0], $kind === 'retry' ? ['retry', 12] : ['fail', 'handler'], ['close']]);
})->with(['retry', 'fail']);

it('preserves a policy error and an additional close error', function (): void {
    $probe = workerProbe();
    $handler = new RuntimeException('handler');
    $policy = new RuntimeException('policy');
    $close = new RuntimeException('close');
    $probe->closeError = $close;
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(static function () use ($handler): never {
        throw $handler;
    }), workerPolicy(static function () use ($policy): never {
        throw $policy;
    }));
    $caught = null;
    try {
        $worker->processOne();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBeInstanceOf(ProcessingException::class)->and($caught->processingError)->toBe($handler)
        ->and($caught->infrastructureError)->toBe($policy)->and($caught->closeError)->toBe($close)
        ->and($probe->calls)->toBe([['receive', 0], ['close']]);
});

it('does not convert unexpected codec defects into a failed message', function (): void {
    $probe = workerProbe();
    $bug = new LogicException('codec defect');
    $codec = new class ($bug) implements MessageCodecInterface {
        public function __construct(private Throwable $bug)
        {
        }
        public function encode(Message $message): RawMessage
        {
            throw $this->bug;
        }
        public function decode(RawMessage $message): Message
        {
            throw $this->bug;
        }
    };
    $worker = new Worker($probe, $codec, new CallbackHandler(static function (): void {
    }), workerNeverRetry());
    $caught = null;
    try {
        $worker->processOne();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caught)->toBe($bug)->and($probe->calls)->toBe([['receive', 0], ['close']]);
});

it('never settles after a caught renewal failure or allows a later renewal to erase it', function (string $outcome, bool $thenThrow): void {
    $probe = workerProbe();
    $infra = match ($outcome) {
        'not-applied' => new TransportException('no effect'),
        'unknown' => new IndeterminateOperationException('unknown'),
        'lost' => new DeliveryLostException('lost'),
    };
    $probe->renewError = $infra;
    $handlerError = new RuntimeException('later handler failure');
    $caughtRenewals = [];
    $handler = new CallbackHandler(function (Message $message, ProcessingContext $context) use ($probe, $thenThrow, $handlerError, &$caughtRenewals): void {
        try {
            $context->lease->renew(100);
        } catch (Throwable $error) {
            $caughtRenewals[] = $error;
        }
        $probe->renewError = null;
        try {
            $context->lease->renew(100);
        } catch (Throwable $error) {
            $caughtRenewals[] = $error;
        }
        if ($thenThrow) {
            throw $handlerError;
        }
    });
    $worker = new Worker($probe, workerCodec(), $handler, workerNeverRetry());
    $caught = null;
    try {
        $worker->processOne();
    } catch (Throwable $error) {
        $caught = $error;
    }
    expect($caughtRenewals)->toBe([$infra, $infra])->and($probe->calls)->toBe([['receive', 0], ['renew', 100], ['close']]);
    if ($thenThrow) {
        expect($caught)->toBeInstanceOf(ProcessingException::class)->and($caught->processingError)->toBe($handlerError)->and($caught->infrastructureError)->toBe($infra);
    } else {
        expect($caught)->toBe($infra);
    }
})->with(['not-applied', 'unknown', 'lost'])->with([false, true]);

it('allows a handler with no lease and rejects invalid wait before I/O', function (): void {
    $probe = workerProbe();
    $probe->hasLease = false;
    $seen = 'unset';
    $worker = new Worker($probe, workerCodec(), new CallbackHandler(function (Message $message, ProcessingContext $context) use (&$seen): void {
        $seen = $context->lease;
    }), workerNeverRetry());
    expect(fn () => $worker->processOne(-1))->toThrow(InvalidArgumentException::class)->and($probe->calls)->toBe([]);
    expect($worker->processOne())->toBe(ProcessingResult::Acknowledged)->and($seen)->toBeNull();
});
