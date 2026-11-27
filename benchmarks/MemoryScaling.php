<?php

declare(strict_types=1);

use Componenta\Queue\Codec\JsonMessageCodec;
use Componenta\Queue\FailureDetails;
use Componenta\Queue\FailureQuery;
use Componenta\Queue\FailureReason;
use Componenta\Queue\InMemory\InMemoryQueue;
use Componenta\Queue\Message;
use Componenta\Queue\MessageLimits;
use Componenta\Queue\MessageType;
use Psr\Clock\ClockInterface;

// This standalone workload must not execute during phpbench discovery.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

require dirname(__DIR__) . '/vendor/autoload.php';

(static function (): void {
    $codec = new JsonMessageCodec(new MessageLimits(1024, 4096, 1024, 8));
    $clock = new class () implements ClockInterface {
        private DateTimeImmutable $instant;
        public function __construct()
        {
            $this->instant = new DateTimeImmutable('2026-11-27T00:00:00Z');
        }
        public function now(): DateTimeImmutable
        {
            return $this->instant;
        }
    };
    $type = new MessageType('benchmark.scale', 1);
    $body = str_repeat('x', 256);
    $makeQueue = static fn (): InMemoryQueue => new InMemoryQueue('scale', $codec, $clock, 10_000, 3);
    $details = new FailureDetails(FailureReason::Handler);

    $measure = static function (callable $operation, int $revolutions): array {
        $samples = [];
        for ($iteration = 0; $iteration < 7; ++$iteration) {
            $start = hrtime(true);
            for ($revolution = 0; $revolution < $revolutions; ++$revolution) {
                $operation();
            }
            $samples[] = (hrtime(true) - $start) / $revolutions / 1000;
        }
        $sorted = $samples;
        sort($sorted, SORT_NUMERIC);
        return ['unit' => 'microseconds/operation', 'revolutions' => $revolutions, 'iterations' => 7, 'median' => $sorted[3], 'minimum' => $sorted[0], 'maximum' => $sorted[6], 'samples' => $samples];
    };

    // Warm up autoload before recording retained heap size.
    $warm = $makeQueue();
    $warm->publisher()->publish(new Message('warm', $type, $body));
    $warm->receiver()->receive()->fail($details);
    $id = $warm->failures()->page(new FailureQuery(1))->items[0]->failureId;
    $warm->failures()->find($id);
    $warm->failures()->redrive($id);
    $warm->receiver()->receive()->acknowledge();
    unset($warm, $id);
    gc_collect_cycles();

    $cases = [];
    foreach ([1000, 10000] as $size) {
        foreach (['completed', 'delayed', 'failed'] as $kind) {
            $before = memory_get_usage(false);
            $queue = $makeQueue();
            $publisher = $queue->publisher();
            $receiver = $queue->receiver();
            for ($index = 0; $index < $size; ++$index) {
                $publisher->publish(new Message('id-' . $index, $type, $body), $kind === 'delayed' ? 60_000 : 0);
                if ($kind !== 'delayed') {
                    $delivery = $receiver->receive() ?? throw new RuntimeException('The setup must consume every published ready message.');
                    $kind === 'completed' ? $delivery->acknowledge() : $delivery->fail($details);
                    unset($delivery);
                }
            }
            if ($receiver->receive() !== null) {
                throw new RuntimeException('The timed poll must not consume ready work.');
            }
            $retainedBytes = memory_get_usage(false) - $before;
            $case = ['records' => $size, 'state' => $kind, 'retained_heap_bytes' => $retainedBytes];
            $case['empty_poll'] = $measure(static function () use ($receiver): void {
                if ($receiver->receive() !== null) {
                    throw new RuntimeException('The clock is frozen; there must be no ready work.');
                }
            }, 1000);
            if ($kind === 'failed') {
                $failures = $queue->failures();
                $first = new FailureQuery(10);
                $cursor = $failures->page(new FailureQuery($size - 10))->nextCursor;
                if ($cursor === null) {
                    throw new RuntimeException('The setup must produce a late continuation cursor.');
                }
                $late = new FailureQuery(10, $cursor);
                $case['first_page_10'] = $measure(static function () use ($failures, $first): void {
                    if (count($failures->page($first)->items) !== 10) {
                        throw new RuntimeException('The first page must contain ten summaries.');
                    }
                }, 100);
                $case['late_page_10'] = $measure(static function () use ($failures, $late): void {
                    $page = $failures->page($late);
                    if (count($page->items) !== 10 || $page->nextCursor !== null) {
                        throw new RuntimeException('The late page must contain the last ten summaries.');
                    }
                }, 100);
                unset($failures, $first, $late, $cursor);
            }
            $cases[] = $case;
            $receiver->close();
            unset($queue, $publisher, $receiver, $case);
            gc_collect_cycles();
        }
    }

    echo json_encode([
        'php' => PHP_VERSION,
        'opcache_enable_cli' => ini_get('opcache.enable_cli'),
        'jit' => ini_get('opcache.jit'),
        'payload_bytes' => strlen($body),
        'clock' => 'frozen',
        'scope' => 'Public InMemory API; retention is intentional; no database throughput claim.',
        'cases' => $cases,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
})();
