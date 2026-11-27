<?php

declare(strict_types=1);

namespace Componenta\Queue\Testing;

use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use Componenta\Queue\PublisherInterface;
use InvalidArgumentException;
use Override;

/**
 * A bounded test double for publication intent and codec validation.
 * It does not deliver, schedule, persist or deduplicate messages.
 */
final class RecordingPublisher implements PublisherInterface
{
    /** @var list<RecordedPublication> */
    private array $records = [];

    public function __construct(
        private readonly MessageCodecInterface $codec,
        private readonly int $capacity,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Recording capacity must be positive.');
        }
    }

    #[Override]
    public function publish(Message $message, int $delayMilliseconds = 0): void
    {
        if ($delayMilliseconds < 0) {
            throw new InvalidArgumentException('Publication delay must be non-negative.');
        }

        $this->codec->encode($message);

        if (count($this->records) >= $this->capacity) {
            throw new TransportException('Recording publisher capacity is exhausted.');
        }

        $this->records[] = new RecordedPublication($message, $delayMilliseconds);
    }

    /** @return list<RecordedPublication> An array snapshot containing immutable records. */
    public function records(): array
    {
        return $this->records;
    }
}
