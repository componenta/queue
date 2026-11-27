<?php

declare(strict_types=1);

namespace Componenta\Queue\InMemory\Internal;

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\Exception\DeliveryLostException;
use Componenta\Queue\Exception\FailureNotFoundException;
use Componenta\Queue\Exception\FailureStateConflictException;
use Componenta\Queue\Exception\MessageConflictException;
use Componenta\Queue\Exception\TransportException;
use Componenta\Queue\FailedMessage;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailurePage;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\FailureStatus;
use Componenta\Queue\Message;
use Componenta\Queue\MessageCodecInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SplQueue;
use Throwable;

/** @internal Shared storage is never exposed by a publisher, receiver or handler. */
final class Store
{
    private const int MAX_CANDIDATES_PER_POLL = 64;

    /** @var array<string,Entry> */
    private array $entries = [];

    /** @var SplQueue<Entry> */
    private SplQueue $candidates;

    /** @var array<string,FailureRecord> */
    private array $failures = [];

    private int $failureSequence = 0;
    private readonly string $scope;
    private readonly string $cursorKey;

    public function __construct(
        private readonly string $queue,
        private readonly MessageCodecInterface $codec,
        private readonly ClockInterface $clock,
        private readonly int $leaseMilliseconds,
        private readonly int $maxDeliveries,
    ) {
        if ($queue === '' || preg_match('//u', $queue) !== 1 || preg_match('/\p{Cc}/u', $queue) !== 0) {
            throw new \InvalidArgumentException('Queue label must be non-empty UTF-8 without control characters.');
        }

        if ($leaseMilliseconds < 1 || $maxDeliveries < 1) {
            throw new \InvalidArgumentException('Lease duration and delivery budget must be positive.');
        }

        $this->candidates = new SplQueue();
        $this->scope = bin2hex(random_bytes(16));
        $this->cursorKey = random_bytes(32);
    }

    public function publish(Message $message, int $delayMilliseconds): void
    {
        if ($delayMilliseconds < 0) {
            throw new \InvalidArgumentException('Publication delay must be nonnegative.');
        }

        $raw = $this->codec->encode($message);
        $headers = $raw->headers->values;
        ksort($headers, SORT_STRING);
        $key = "\0".$message->id;
        $existing = $this->entries[$key] ?? null;

        if ($existing !== null) {
            if (
                $existing->raw->body !== $raw->body
                || $existing->comparisonHeaders !== $headers
                || $existing->originalDelay !== $delayMilliseconds
            ) {
                throw new MessageConflictException('The retained message ID has different publication content or delay.');
            }

            return;
        }

        $now = $this->now();
        $entry = new Entry($message->id, $raw, $headers, $delayMilliseconds, Time::after($now, $delayMilliseconds), $now);
        $this->entries[$key] = $entry;
        $this->queueIfActive($entry);
    }

    public function claim(Session $session): ?DeliveryState
    {
        $visits = min($this->candidates->count(), self::MAX_CANDIDATES_PER_POLL);
        $visitedEntries = [];
        $pendingFailures = [];
        $failureSequence = $this->failureSequence;

        try {
            for ($visited = 0; $visited < $visits; ++$visited) {
                $entry = $this->candidates->dequeue();
                $entry->queued = false;
                $visitedEntries[] = $entry;

                $now = $this->now();
                $eligible = ($entry->status === EntryStatus::Ready && $entry->availableAt <= $now)
                    || ($entry->status === EntryStatus::Leased && $entry->leaseUntil !== null && $entry->leaseUntil <= $now);

                if (!$eligible) {
                    continue;
                }

                if ($entry->deliveryCount >= $this->maxDeliveries) {
                    $failureSequence = Time::increment($failureSequence);
                    $pendingFailures[] = $this->prepareFailure($entry, new FailureDetails(FailureReason::DeliveryLimit), $now, $failureSequence);
                    continue;
                }

                $deliveryCount = Time::increment($entry->deliveryCount);
                $lifetimeDeliveries = Time::increment($entry->lifetimeDeliveries);
                $leaseUntil = Time::after($now, $this->leaseMilliseconds);
                $token = new \stdClass();
                $metadata = new DeliveryMetadata($this->queue, $entry->retryCount, $deliveryCount, CountAccuracy::Exact, $now);
                $state = new DeliveryState($this, $session, $entry, $token, $entry->raw, $metadata);

                // A failed poll must not expose maintenance from earlier candidates.
                foreach ($pendingFailures as $record) {
                    $this->commitFailure($record);
                }
                $entry->status = EntryStatus::Leased;
                $entry->deliveryCount = $deliveryCount;
                $entry->lifetimeDeliveries = $lifetimeDeliveries;
                $entry->leaseUntil = $leaseUntil;
                $entry->receivedAt = $now;
                $entry->token = $token;

                return $state;
            }

            foreach ($pendingFailures as $record) {
                $this->commitFailure($record);
            }

            return null;
        } finally {
            foreach ($visitedEntries as $entry) {
                $this->queueIfActive($entry);
            }
        }
    }

    public function ownedNow(Entry $entry, object $token): DateTimeImmutable
    {
        $now = $this->now();

        if (
            $entry->status !== EntryStatus::Leased
            || $entry->token !== $token
            || $entry->leaseUntil === null
            || $entry->leaseUntil <= $now
        ) {
            throw new DeliveryLostException('The reservation expired or belongs to a newer delivery.');
        }

        return $now;
    }

    public function acknowledge(Entry $entry): void
    {
        $entry->status = EntryStatus::Completed;
        $entry->token = null;
        $entry->leaseUntil = null;
    }

    public function retry(Entry $entry, int $delayMilliseconds, DateTimeImmutable $now): void
    {
        $availableAt = Time::after($now, $delayMilliseconds);
        $retryCount = Time::increment($entry->retryCount);

        $entry->status = EntryStatus::Ready;
        $entry->availableAt = $availableAt;
        $entry->retryCount = $retryCount;
        $entry->token = null;
        $entry->leaseUntil = null;
        $this->queueIfActive($entry);
    }

    public function fail(Entry $entry, FailureDetails $details, DateTimeImmutable $now): void
    {
        $sequence = Time::increment($this->failureSequence);
        $this->commitFailure($this->prepareFailure($entry, $details, $now, $sequence));
    }

    private function prepareFailure(Entry $entry, FailureDetails $details, DateTimeImmutable $now, int $sequence): FailureRecord
    {
        $id = $this->scope.'-'.$sequence;
        $metadata = new DeliveryMetadata(
            $this->queue,
            $entry->retryCount,
            $entry->deliveryCount,
            CountAccuracy::Exact,
            $entry->receivedAt,
        );
        return new FailureRecord($id, $sequence, $entry, $entry->generation, $metadata, $details, $now);
    }

    private function commitFailure(FailureRecord $record): void
    {
        $this->failures[$record->id] = $record;
        $this->failureSequence = $record->sequence;
        $entry = $record->entry;
        $entry->status = EntryStatus::Failed;
        $entry->failureId = $record->id;
        $entry->token = null;
        $entry->leaseUntil = null;
    }

    public function findFailure(string $failureId): ?FailedMessage
    {
        return ($this->failures[$failureId] ?? null)?->snapshot();
    }

    public function failurePage(FailureQuery $query): FailurePage
    {
        $after = $query->cursor === null ? 0 : $this->readCursor($query->cursor, $query->status);
        $items = [];
        $last = $after;
        $more = false;

        foreach ($this->failures as $record) {
            if ($record->sequence <= $after || ($query->status !== null && $record->status !== $query->status)) {
                continue;
            }

            if (count($items) === $query->limit) {
                $more = true;
                break;
            }

            $items[] = $record->summary();
            $last = $record->sequence;
        }

        return new FailurePage($items, $more ? $this->cursor($last, $query->status) : null);
    }

    public function redrive(string $failureId, int $delayMilliseconds): void
    {
        if ($delayMilliseconds < 0) {
            throw new \InvalidArgumentException('Redrive delay must be nonnegative.');
        }

        $record = $this->failure($failureId);

        if ($record->status === FailureStatus::Redriven) {
            if ($record->redriveDelay !== $delayMilliseconds) {
                throw new FailureStateConflictException('The failure was already redriven with another delay.');
            }

            return;
        }

        if ($record->status === FailureStatus::Discarded) {
            throw new FailureStateConflictException('A discarded failure cannot be redriven.');
        }

        $entry = $this->pendingEntry($record);
        $now = $this->now();
        $availableAt = Time::after($now, $delayMilliseconds);
        $generation = Time::increment($entry->generation);
        $lifetimeRedrives = Time::increment($entry->lifetimeRedrives);

        $entry->status = EntryStatus::Ready;
        $entry->generation = $generation;
        $entry->deliveryCount = 0;
        $entry->retryCount = 0;
        $entry->lifetimeRedrives = $lifetimeRedrives;
        $entry->availableAt = $availableAt;
        $entry->receivedAt = $now;
        $entry->failureId = null;
        $record->status = FailureStatus::Redriven;
        $record->redriveDelay = $delayMilliseconds;
        $this->queueIfActive($entry);
    }

    public function discard(string $failureId): void
    {
        $record = $this->failure($failureId);

        if ($record->status === FailureStatus::Discarded) {
            return;
        }

        if ($record->status === FailureStatus::Redriven) {
            throw new FailureStateConflictException('Discard cannot cancel an already redriven processing run.');
        }

        $entry = $this->pendingEntry($record);
        $entry->status = EntryStatus::Discarded;
        $record->status = FailureStatus::Discarded;
    }

    private function queueIfActive(Entry $entry): void
    {
        if (!$entry->queued && ($entry->status === EntryStatus::Ready || $entry->status === EntryStatus::Leased)) {
            $entry->queued = true;
            $this->candidates->enqueue($entry);
        }
    }

    private function now(): DateTimeImmutable
    {
        try {
            return DateTimeImmutable::createFromInterface($this->clock->now());
        } catch (Throwable $error) {
            throw new TransportException('The queue clock could not supply its current instant.', previous: $error);
        }
    }

    private function failure(string $id): FailureRecord
    {
        return $this->failures[$id] ?? throw new FailureNotFoundException('The failure record does not exist.');
    }

    private function pendingEntry(FailureRecord $record): Entry
    {
        $entry = $record->entry;

        if (
            $entry->status !== EntryStatus::Failed
            || $entry->failureId !== $record->id
            || $entry->generation !== $record->generation
        ) {
            throw new \LogicException('An unresolved failure does not own its failed processing generation.');
        }

        return $entry;
    }

    private function cursor(int $after, ?FailureStatus $status): string
    {
        $payload = base64_encode(json_encode(['after' => $after, 'status' => $status?->value], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->cursorKey);
    }

    private function readCursor(string $cursor, ?FailureStatus $status): int
    {
        if (strlen($cursor) > 512 || substr_count($cursor, '.') !== 1) {
            throw new \InvalidArgumentException('Invalid failure-page cursor.');
        }

        [$payload, $signature] = explode('.', $cursor, 2);

        if (!hash_equals(hash_hmac('sha256', $payload, $this->cursorKey), $signature)) {
            throw new \InvalidArgumentException('The cursor does not belong to this failure store.');
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid failure-page cursor.');
        }

        $data = json_decode($decoded, true, 3, JSON_THROW_ON_ERROR);

        if (
            !is_array($data)
            || !isset($data['after'])
            || !is_int($data['after'])
            || $data['after'] < 1
            || !array_key_exists('status', $data)
            || $data['status'] !== $status?->value
        ) {
            throw new \InvalidArgumentException('The cursor is invalid or belongs to another status filter.');
        }

        return $data['after'];
    }
}
