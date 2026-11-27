<?php

declare(strict_types=1);

namespace Componenta\Queue\Retry;

use Closure;
use Componenta\Queue\FailureContext;
use Componenta\Queue\RetryPolicyInterface;
use InvalidArgumentException;
use Override;
use Throwable;
use UnexpectedValueException;

/** Limits accepted scheduled retries, independently of native redelivery counts. */
final readonly class LimitedRetryPolicy implements RetryPolicyInterface
{
    /** @var Closure(Throwable): mixed */
    private Closure $shouldRetry;

    /** @param callable(Throwable): bool $shouldRetry */
    public function __construct(
        private int $maxRetries,
        callable $shouldRetry,
        private ExponentialBackoff $backoff,
    ) {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('Maximum retries must be non-negative.');
        }

        $this->shouldRetry = Closure::fromCallable($shouldRetry);
    }

    #[Override]
    public function decide(FailureContext $failure): RetryAfter|Fail
    {
        if ($failure->metadata->retryCount >= $this->maxRetries) {
            return new Fail();
        }

        if (!self::validatedClassification(($this->shouldRetry)($failure->error))) {
            return new Fail();
        }

        return new RetryAfter($this->backoff->delayFor($failure->metadata->retryCount + 1));
    }

    private static function validatedClassification(mixed $result): bool
    {
        if (!is_bool($result)) {
            throw new UnexpectedValueException('Retry classifier must return a boolean.');
        }

        return $result;
    }
}
