<?php

declare(strict_types=1);

namespace Componenta\Queue\Retry;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/** A capped exponential interval with full jitter, measured in milliseconds. */
final readonly class ExponentialBackoff
{
    /** @var Closure(int, int): mixed */
    private Closure $randomInt;

    /** @param (callable(int, int): int)|null $randomInt */
    public function __construct(
        private int $initialDelayMilliseconds,
        private int $maximumDelayMilliseconds,
        private float $multiplier = 2.0,
        ?callable $randomInt = null,
    ) {
        if ($initialDelayMilliseconds < 0 || $maximumDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Backoff delays must be non-negative.');
        }

        if (!is_finite($multiplier) || $multiplier < 1.0) {
            throw new InvalidArgumentException('Backoff multiplier must be finite and at least one.');
        }

        $this->randomInt = Closure::fromCallable($randomInt ?? random_int(...));
    }

    public function delayFor(int $retryOrdinal): int
    {
        if ($retryOrdinal < 1) {
            throw new InvalidArgumentException('Retry ordinal must be positive.');
        }

        $maximum = $this->intervalFor($retryOrdinal);
        return self::validatedRandomResult(($this->randomInt)(0, $maximum), $maximum);
    }

    private static function validatedRandomResult(mixed $delay, int $maximum): int
    {
        if (!is_int($delay) || $delay < 0 || $delay > $maximum) {
            throw new UnexpectedValueException(sprintf(
                'Backoff random source must return an integer between 0 and %d; got %s.',
                $maximum,
                get_debug_type($delay),
            ));
        }

        return $delay;
    }

    private function intervalFor(int $retryOrdinal): int
    {
        $initial = min($this->initialDelayMilliseconds, $this->maximumDelayMilliseconds);

        if ($retryOrdinal === 1 || $initial === 0 || $initial === $this->maximumDelayMilliseconds || $this->multiplier === 1.0) {
            return $initial;
        }

        $maximum = (float) $this->maximumDelayMilliseconds;
        $interval = (float) $initial;
        $factor = min($this->multiplier, $maximum);
        $exponent = $retryOrdinal - 1;

        // Saturating exponentiation takes logarithmic work even for PHP_INT_MAX.
        // Check before multiplication or float-to-int conversion can overflow.
        while ($exponent > 0) {
            if (($exponent & 1) === 1) {
                if ($interval >= $maximum / $factor) {
                    return $this->maximumDelayMilliseconds;
                }

                $interval *= $factor;
            }

            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                $factor = $factor >= $maximum / $factor ? $maximum : $factor * $factor;
            }
        }

        return min($this->maximumDelayMilliseconds, (int) $interval);
    }
}
