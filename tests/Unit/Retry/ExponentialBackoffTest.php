<?php

declare(strict_types=1);

namespace Componenta\Queue\Tests\Unit\Retry;

use Componenta\Queue\Retry\ExponentialBackoff;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

it('increases the retry interval exponentially and caps later retries', function (): void {
    $backoff = new ExponentialBackoff(100, 750, randomInt: static fn (int $min, int $max): int => $max);

    expect(array_map($backoff->delayFor(...), [1, 2, 3, 4, 5]))->toBe([100, 200, 400, 750, 750]);
});

it('retains fractional growth until the final integer millisecond interval', function (): void {
    $backoff = new ExponentialBackoff(3, 100, 1.5, static fn (int $min, int $max): int => $max);

    expect(array_map($backoff->delayFor(...), [1, 2, 3, 4]))->toBe([3, 4, 6, 10]);
});

it('passes the full inclusive jitter interval to the configured random source', function (int $selected): void {
    $bounds = [];
    $backoff = new ExponentialBackoff(100, 1_000, randomInt: static function (int $min, int $max) use (&$bounds, $selected): int {
        $bounds[] = [$min, $max];

        return $selected;
    });

    expect($backoff->delayFor(1))->toBe($selected)
        ->and($bounds)->toBe([[0, 100]]);
})->with([0, 37, 100]);

it('handles zero intervals and an initial interval above the cap', function (int $initial, int $maximum, int $expected): void {
    $backoff = new ExponentialBackoff($initial, $maximum, randomInt: static fn (int $min, int $max): int => $max);

    expect($backoff->delayFor(1))->toBe($expected)
        ->and($backoff->delayFor(PHP_INT_MAX))->toBe($expected);
})->with([
    'zero initial' => [0, 100, 0],
    'zero maximum' => [100, 0, 0],
    'both zero' => [0, 0, 0],
    'initial above maximum' => [500, 100, 100],
]);

it('caps an enormous retry ordinal without overflowing its interval', function (): void {
    $backoff = new ExponentialBackoff(1, PHP_INT_MAX, randomInt: static fn (int $min, int $max): int => $max);

    expect($backoff->delayFor(PHP_INT_SIZE * 8 - 1))->toBe(intdiv(PHP_INT_MAX, 2) + 1)
        ->and($backoff->delayFor(PHP_INT_SIZE * 8))->toBe(PHP_INT_MAX)
        ->and($backoff->delayFor(PHP_INT_MAX))->toBe(PHP_INT_MAX);
});

it('preserves a large exact integer interval when the multiplier is one', function (): void {
    $backoff = new ExponentialBackoff(PHP_INT_MAX - 1, PHP_INT_MAX, 1.0, static fn (int $min, int $max): int => $max);

    expect($backoff->delayFor(1))->toBe(PHP_INT_MAX - 1)
        ->and($backoff->delayFor(PHP_INT_MAX))->toBe(PHP_INT_MAX - 1);
});

it('caps an enormous finite multiplier before it can overflow', function (): void {
    $backoff = new ExponentialBackoff(2, 1_000, PHP_FLOAT_MAX, static fn (int $min, int $max): int => $max);

    expect($backoff->delayFor(1))->toBe(2)
        ->and($backoff->delayFor(2))->toBe(1_000);
});

it('rejects invalid backoff configuration', function (int $initial, int $maximum, float $multiplier): void {
    expect(fn () => new ExponentialBackoff($initial, $maximum, $multiplier))->toThrow(InvalidArgumentException::class);
})->with([
    'negative initial' => [-1, 100, 2.0],
    'negative maximum' => [100, -1, 2.0],
    'decreasing multiplier' => [100, 1_000, 0.99],
    'infinite multiplier' => [100, 1_000, INF],
    'negative infinite multiplier' => [100, 1_000, -INF],
    'not a number multiplier' => [100, 1_000, NAN],
]);

it('rejects invalid retry ordinals before consulting randomness', function (int $ordinal): void {
    $calls = 0;
    $backoff = new ExponentialBackoff(100, 1_000, randomInt: static function (int $min, int $max) use (&$calls): int {
        ++$calls;

        return $max;
    });

    expect(fn () => $backoff->delayFor($ordinal))->toThrow(InvalidArgumentException::class)
        ->and($calls)->toBe(0);
})->with([0, -1]);

it('rejects random results outside the requested integer interval', function (mixed $selected): void {
    $backoff = new ExponentialBackoff(100, 1_000, randomInt: static fn (int $min, int $max): mixed => $selected);

    expect(fn () => $backoff->delayFor(1))->toThrow(UnexpectedValueException::class);
})->with([-1, 101, '50', 50.0, true, null]);

it('propagates random source failures without replacing their cause', function (): void {
    $failure = new RuntimeException('Random source unavailable.');
    $backoff = new ExponentialBackoff(100, 1_000, randomInt: static fn (int $min, int $max): never => throw $failure);
    $caught = null;

    try {
        $backoff->delayFor(1);
    } catch (RuntimeException $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
});

it('uses a valid bounded default random source', function (): void {
    expect((new ExponentialBackoff(1, 1))->delayFor(1))->toBeIn([0, 1]);
});
