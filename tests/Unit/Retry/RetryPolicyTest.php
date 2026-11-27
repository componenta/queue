<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit\Retry;

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\FailureContext;
use Componenta\Queue\Headers;
use Componenta\Queue\Message;
use Componenta\Queue\MessageType;
use Componenta\Queue\Retry\ExponentialBackoff;
use Componenta\Queue\Retry\Fail;
use Componenta\Queue\Retry\LimitedRetryPolicy;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Retry\RetryAfter;
use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

function retryFailure(int $retryCount = 0, ?Throwable $error = null): FailureContext
{
    return new FailureContext(
        new Message('message-1', new MessageType('invoice.generate', 1), '{}', new Headers([])),
        new DeliveryMetadata('invoices', $retryCount, 1, CountAccuracy::Exact, new DateTimeImmutable('2026-01-01T00:00:00Z')),
        $error ?? new RuntimeException('Temporary service failure.'),
    );
}

it('accepts nonnegative retry delays without changing their units', function (int $delay): void {
    expect((new RetryAfter($delay))->delayMilliseconds)->toBe($delay);
})->with([0, 1, PHP_INT_MAX]);

it('rejects a negative retry delay', function (): void {
    expect(fn () => new RetryAfter(-1))->toThrow(InvalidArgumentException::class);
});

it('never retries when the explicit no retry policy is selected', function (): void {
    expect((new NoRetryPolicy())->decide(retryFailure()))->toBeInstanceOf(Fail::class);
});

it('stops at its retry budget before invoking application callbacks', function (int $maximum, int $alreadyRetried): void {
    $calls = [];
    $backoff = new ExponentialBackoff(100, 1_000, randomInt: static function (int $min, int $max) use (&$calls): int {
        $calls[] = 'random';

        return $max;
    });
    $policy = new LimitedRetryPolicy($maximum, static function (Throwable $error) use (&$calls): bool {
        $calls[] = 'classifier';

        return true;
    }, $backoff);

    expect($policy->decide(retryFailure($alreadyRetried)))->toBeInstanceOf(Fail::class)
        ->and($calls)->toBe([]);
})->with([
    'no retries allowed' => [0, 0],
    'budget reached' => [2, 2],
    'budget already exceeded' => [2, 3],
    'maximum integer budget reached' => [PHP_INT_MAX, PHP_INT_MAX],
]);

it('does not calculate a delay for a nonretryable failure', function (): void {
    $randomCalls = 0;
    $policy = new LimitedRetryPolicy(3, static fn (Throwable $error): bool => false, new ExponentialBackoff(100, 1_000, randomInt: static function (int $min, int $max) use (&$randomCalls): int {
        ++$randomCalls;

        return $max;
    }));

    expect($policy->decide(retryFailure()))->toBeInstanceOf(Fail::class)
        ->and($randomCalls)->toBe(0);
});

it('classifies the original throwable and bases the next delay on accepted retries', function (): void {
    $original = new Error('Retryable application failure.');
    $classified = null;
    $policy = new LimitedRetryPolicy(3, static function (Throwable $error) use (&$classified): bool {
        $classified = $error;

        return true;
    }, new ExponentialBackoff(100, 1_000, randomInt: static fn (int $min, int $max): int => $max));

    $decision = $policy->decide(retryFailure(1, $original));

    expect($classified)->toBe($original)
        ->and($decision)->toBeInstanceOf(RetryAfter::class)
        ->and($decision->delayMilliseconds)->toBe(200);
});

it('does not retain an attempt counter across different messages', function (): void {
    $policy = new LimitedRetryPolicy(3, static fn (Throwable $error): bool => true, new ExponentialBackoff(100, 1_000, randomInt: static fn (int $min, int $max): int => $max));

    $laterAttempt = $policy->decide(retryFailure(2));
    $firstAttempt = $policy->decide(retryFailure(0));

    expect($laterAttempt)->toBeInstanceOf(RetryAfter::class)
        ->and($laterAttempt->delayMilliseconds)->toBe(400)
        ->and($firstAttempt)->toBeInstanceOf(RetryAfter::class)
        ->and($firstAttempt->delayMilliseconds)->toBe(100);
});

it('handles the final permitted retry without overflowing its ordinal', function (): void {
    $policy = new LimitedRetryPolicy(PHP_INT_MAX, static fn (Throwable $error): bool => true, new ExponentialBackoff(1, 1_000, randomInt: static fn (int $min, int $max): int => $max));

    $decision = $policy->decide(retryFailure(PHP_INT_MAX - 1));

    expect($decision)->toBeInstanceOf(RetryAfter::class)
        ->and($decision->delayMilliseconds)->toBe(1_000);
});

it('rejects a negative retry budget', function (): void {
    expect(fn () => new LimitedRetryPolicy(-1, static fn (Throwable $error): bool => true, new ExponentialBackoff(100, 1_000)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a classifier result that is not a boolean', function (mixed $selected): void {
    $policy = new LimitedRetryPolicy(1, static fn (Throwable $error): mixed => $selected, new ExponentialBackoff(100, 1_000));

    expect(fn () => $policy->decide(retryFailure()))->toThrow(UnexpectedValueException::class);
})->with([1, 'yes', null]);

it('propagates a classifier failure as the same failure', function (): void {
    $failure = new RuntimeException('Classifier unavailable.');
    $policy = new LimitedRetryPolicy(1, static fn (Throwable $error): never => throw $failure, new ExponentialBackoff(100, 1_000));
    $caught = null;

    try {
        $policy->decide(retryFailure());
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
});
