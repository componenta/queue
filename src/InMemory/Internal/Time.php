<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\Exception\UnsupportedFeatureException;
use DateTimeImmutable;

/** @internal Integer arithmetic preserves microseconds without a floating epoch. */
final class Time
{
    public static function after(DateTimeImmutable $now, int $milliseconds): DateTimeImmutable
    {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('Delay must be nonnegative.');
        }

        $seconds = intdiv($milliseconds, 1000);
        $micros = (int) $now->format('u') + ($milliseconds % 1000) * 1000;
        $seconds += intdiv($micros, 1_000_000);
        $micros %= 1_000_000;
        $timestamp = $now->getTimestamp();

        if ($timestamp > PHP_INT_MAX - $seconds) {
            throw new UnsupportedFeatureException('The requested duration exceeds the supported timestamp range.');
        }

        $timestamp += $seconds;
        $instant = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $timestamp, $micros));

        if ($instant === false || $instant->getTimestamp() !== $timestamp || (int) $instant->format('u') !== $micros) {
            throw new UnsupportedFeatureException('The requested instant cannot be represented without loss.');
        }

        return $instant;
    }

    public static function increment(int $counter): int
    {
        if ($counter === PHP_INT_MAX) {
            throw new UnsupportedFeatureException('The queue counter cannot be incremented without overflow.');
        }

        return $counter + 1;
    }
}
