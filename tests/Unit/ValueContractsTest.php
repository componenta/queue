<?php

declare(strict_types=1);

use Componenta\Queue\CountAccuracy;
use Componenta\Queue\DeliveryMetadata;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailurePage;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\FailureStatus;
use Componenta\Queue\FailureSummary;
use Componenta\Queue\LeaseSnapshot;

it('rejects inconsistent or invalid delivery counters', function (int $retry, ?int $deliveries, string $accuracy): void {
    expect(fn () => new DeliveryMetadata('jobs', $retry, $deliveries, CountAccuracy::from($accuracy), new DateTimeImmutable()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative retries' => [-1, 1, 'exact'],
    'zero exact deliveries' => [0, 0, 'exact'],
    'negative approximate deliveries' => [0, -1, 'approximate'],
    'missing exact count' => [0, null, 'exact'],
    'missing approximate count' => [0, null, 'approximate'],
    'unknown with count' => [0, 1, 'unknown'],
]);

it('copies date values instead of retaining a mutable subclass', function (): void {
    $date = new class ('2026-11-27T12:00:00+02:00') extends DateTimeImmutable {
        public string $mutableProperty = 'before';
    };
    $metadata = new DeliveryMetadata('jobs', 0, null, CountAccuracy::Unknown, $date);
    $lease = new LeaseSnapshot($date);
    $date->mutableProperty = 'after';
    expect($metadata->receivedAt)->not->toBe($date)
        ->and($metadata->receivedAt::class)->toBe(DateTimeImmutable::class)
        ->and($metadata->receivedAt->format('c'))->toBe('2026-11-27T12:00:00+02:00')
        ->and($lease->expiresAt)->not->toBe($date);
});

it('takes a failure page snapshot even when the input contains references', function (): void {
    $item = new FailureSummary('f1', 'jobs', 'm1', new DateTimeImmutable(), FailureReason::Handler, FailureStatus::Pending);
    $page = new FailurePage([&$item], 'cursor');
    $item = new FailureSummary('f2', 'jobs', 'm2', new DateTimeImmutable(), FailureReason::Handler, FailureStatus::Discarded);
    expect($page->items[0]->failureId)->toBe('f1');
});

it('validates a bounded failure query and diagnostics', function (): void {
    expect(fn () => new FailureQuery(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new FailureQuery(1, ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new FailureDetails(FailureReason::Handler, str_repeat('x', 256)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new FailureDetails(FailureReason::Handler, null, str_repeat('x', 2049)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new FailureDetails(FailureReason::Handler, null, "bad\xFF"))->toThrow(InvalidArgumentException::class);
});
