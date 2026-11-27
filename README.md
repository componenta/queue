# Componenta Queue

An independent PHP queue library with immutable messages, explicit delivery
ownership, bounded retries and recoverable failed work. The core has no dependency
on Componenta App, CQRS, a container, an ORM or a database driver.

[Русский обзор](README.ru.md)

## Packages

Each integration has its own repository and Composer manifest. The core remains
usable without a framework, database or console application.

| Package | Purpose |
| --- | --- |
| `componenta/queue` | Messages, codec, Worker, handlers, retry policy, memory adapter and test publisher |
| [componenta/queue-cycle](https://github.com/componenta/queue-cycle) | Persistent PostgreSQL queue using Cycle Database |
| [componenta/queue-console](https://github.com/componenta/queue-console) | Worker loop, process limits, signals and failure administration |
| [componenta/queue-app](https://github.com/componenta/queue-app) | App registration, prepared handler maps and per-message service scope |
| [componenta/cqrs-queue](https://github.com/componenta/cqrs-queue) | Explicit command publication and synchronous consumption through CQRS |

## Requirements and development

The project baseline is PHP `^8.5.13`. The core uses `ext-json`, PSR-20 Clock and
PSR-3 Log. In a development checkout:

```sh
composer install
composer test
composer analyse
composer cs:check
php examples/basic.php
```

`composer test` runs core, memory-adapter and portable-contract tests without a
database. The [PostgreSQL adapter](https://github.com/componenta/queue-cycle)
contains its own real-database suite, schema installation, transactional staging,
retention and connection guidance. [Implementation evidence](docs/implementation.md)
records executed checks and the integration dependency baselines.

## First message

The complete runnable composition is [examples/basic.php](examples/basic.php).
With a configured queue, codec and application handler, the public processing
path is small:

```php
use Componenta\Queue\Message;
use Componenta\Queue\MessageType;
use Componenta\Queue\Retry\NoRetryPolicy;
use Componenta\Queue\Worker;

$queue->publisher()->publish(new Message(
    id: 'notification-123',
    type: new MessageType('notification.send', schemaVersion: 1),
    body: 'Hello from Componenta Queue',
));

$receiver = $queue->receiver();
$worker = new Worker($receiver, $codec, $handler, new NoRetryPolicy());

try {
    $result = $worker->processOne();
} finally {
    $receiver->close();
}
```

The producer supplies a stable message ID. Preserve it when recovering an
uncertain publication. Recreating an application operation with a new ID creates
a different publication. Handlers receive `Message` and `ProcessingContext`;
they return normally after success or throw. They can renew an available lease,
but do not receive acknowledgment or rejection authority.

`HandlerRegistry` optionally routes the exact message alias and schema version to
an explicit `HandlerRegistration`. `CallbackHandler` adapts a callable. Unknown
routes are permanent rejections; transported names never select arbitrary PHP
classes.

`processOne()` returns only a completed outcome:

| Result | Meaning |
| --- | --- |
| `Idle` | No delivery was returned; the adapter may have performed bounded recovery work |
| `Acknowledged` | The handler returned and acknowledgment succeeded |
| `RetryScheduled` | The selected delayed retry was accepted |
| `Failed` | The raw message and bounded failure details were captured |

Infrastructure errors are exceptions. The Worker closes its receiver before
propagating them and cannot be reused afterward. Create a fresh session for
recovery. A handler failure followed by a policy/settlement failure retains both
causes in `ProcessingException`, including an additional close failure.

## Wire format and limits

`JsonMessageCodec` takes explicit `MessageLimits` for body bytes, complete wire
bytes, header bytes and JSON depth. It preserves arbitrary body bytes using
canonical padded base64, checks the exact wire content type, rejects duplicate
JSON members and validates the fixed versioned envelope. Application headers are
immutable UTF-8 string maps. No PHP object serialization, executable class name,
compression or implicit payload conversion is involved.

Malformed input remains available as `RawMessage`. A decoder rejection is stored
as a decode failure without invoking a handler. An unexpected codec or adapter
failure remains an infrastructure error.

## Retry, leases and failed work

`NoRetryPolicy` is the explicit baseline. `LimitedRetryPolicy` requires a finite
retry budget, an application classifier and `ExponentialBackoff`. Backoff uses a
capped interval and full jitter; scheduling never sleeps inside the Worker.
Scheduled retry count and native delivery count are separate. Adapter delivery
budgets also cover reservations lost to process crashes.

Successful business effects precede acknowledgment. If acknowledgment has an
unknown outcome, the Worker does not attempt a different disposition. Any failed
lease renewal is sticky for that processing step, even when the handler catches
the exception. No later acknowledgment, retry or failure capture follows it.

Failure stores expose bounded summary pages, individual raw snapshots, redrive
and discard. Redriving the same failure with the same delay is idempotent while
that history remains retained; conflicting redrive/discard requests fail
explicitly. Redrive starts a new processing generation with fresh counters and
preserves the logical message and raw content. Default diagnostic capture includes
a category and exception type, without exception messages, traces, bodies or
headers.

The memory adapter is process-local and retains its history for its lifetime. It
is useful for deterministic tests and examples; persistent work belongs in the
PostgreSQL adapter. `Testing\RecordingPublisher` is a capacity-bounded publication
spy that also exercises the configured codec. It does not deliver messages.

## Guarantees and operating boundaries

The PostgreSQL profile provides atomic local transitions, fenced reservations,
strict lease expiry checked against database time, retained publication identity
and transactional staging on the application's existing write connection.
`TransactionalPublisher::stage()` does not commit the caller's transaction.
Ordinary queue operations reject an unrelated open transaction.

Application effects must tolerate redelivery. A queue transaction cannot make an
external effect exactly once. Use an application inbox or an external service's
idempotency key where that guarantee is required. The core does not install
signals, supervise processes, interrupt arbitrary PHP handlers or promise global
FIFO ordering. Configure delivery leases and supervisor shutdown grace for the
actual workload.

## Design and evidence

| Document | Content |
| --- | --- |
| [Behavioral specification](docs/specification.md) | Public contracts, wire format, ownership, outcome and error semantics |
| [Cycle / PostgreSQL profile](docs/cycle-profile.md) | Durable transitions, strict leases, recovery, retention and staging |
| [Implementation evidence](docs/implementation.md) | Delivered code, executed tests, measurements and remaining release gates |
| [Verification plan](docs/verification.md) | Traceable core/database scenarios and implementation order |
| [Componenta integrations](docs/integrations.md) | CQRS, App and Console boundaries and dependency baselines |
| [Research](docs/research.md) | Source comparison, standards and rationale |

Run `composer bench` for local codec, registry, backoff and memory-round-trip
subjects. These measurements are separate from committed database workloads and
are not a database throughput claim.

## License

[MIT](LICENSE).
