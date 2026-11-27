# Componenta Queue: behavioral specification

**Status:** this document is the normative contract for the implemented core and first PostgreSQL adapter. Executed tests, measurements and remaining release gates are recorded separately in [implementation evidence](implementation.md). Optional integrations retain the release constraints in [integrations](integrations.md).

This document owns shared behavior. [The Cycle profile](cycle-profile.md) owns the stronger PostgreSQL guarantees. [Integrations](integrations.md) owns CQRS, App and Console composition. [Verification](verification.md) derives acceptance scenarios and implementation order. [Research](research.md) explains the external evidence and alternatives. PHP declarations below specify the public API shape; implementation is in `src/` and the separate `packages/cycle/` package.

## 1. Objective and scope

Provide a reusable PHP queue for background work with competing consumers: notifications, webhook delivery, imports, document generation and asynchronous commands. Applications must be able to publish an immutable message, process a particular delivery, defer a failed attempt, recover work after a process failure, and inspect or explicitly replay failed work.

The first implementation consists of `componenta/queue` and a separate `componenta/queue-cycle` adapter. PostgreSQL 16 and later are the initial database conformance target. `componenta/cqrs-queue`, `componenta/queue-console` and `componenta/queue-app` are separate integrations. Broker adapters follow their own verified profiles; they are not certified by this specification.

The core is not another command bus, an RPC system, a distributed scheduler, a workflow engine, a broadcast/event-log API, or a broker administration API. Batch processing, priority, global FIFO, automatic failover between destinations, and exactly-once business effects are outside the initial contract. Delayed eligibility is included; execution at an exact wall-clock instant is not promised.

### Project decisions and evidence

- Use `strict_types`, PSR-4 and PSR-12. Adopt PHP `^8.5.13` as the Componenta baseline, matching the inspected CQRS compatibility branch. This is a project decision, not a technical requirement imposed by a queue protocol.
- Use Pest for behavior tests and phpbench for measurements, consistent with the existing Componenta work. Development tools do not become runtime dependencies.
- Reuse PSR-3 for logging and PSR-20 where wall-clock access is actually needed, including the in-memory adapter. A worker does not acquire a clock dependency merely to generate timestamps it does not use.
- Accept a caller-supplied message ID. Applications may use `componenta/identity`; the core does not implement UUID generation or acquire Config/DI dependencies through an ID factory.
- Existing CQRS transports supply useful fencing, failure-storage and context-isolation invariants. They do not define the new core API. The inspected revisions and migration are in [integrations](integrations.md).

## 2. Ownership and dependencies

| Owner | Responsibility | Independent boundary | Excluded responsibility |
| --- | --- | --- | --- |
| Core message values and codec | Immutable data, validation, versioned wire representation | `MessageCodecInterface` | PHP object reconstruction selected by an incoming class name |
| Publisher | Acceptance by one configured destination | `PublisherInterface` | Application routing, business commit, handler execution |
| Receiver and delivery | Reservation, recovery and the authority to settle that reservation | `ReceiverInterface`, `DeliveryInterface` | Business idempotency, application transactions |
| Worker | One receive/decode/handle/settle step | Concrete `Worker` | Process supervision, signals, containers, automatic background heartbeats |
| Retry policy | A bounded decision for a handler failure | `RetryPolicyInterface` | Sleeping, SQL, republishing or acknowledgment |
| Handler | Application work | `MessageHandlerInterface` | Acknowledgment and broker connection access |
| Failure management | Inspection and explicit disposition of failed work | `FailedMessageStoreInterface` | Automatic replay or inferred authorization |
| Adapter | Storage/protocol implementation of declared guarantees | Implements the relevant ports | Weakening behavior silently to match its client library |

The core has no dependency on CQRS, App, Config, DI, Cycle ORM, a PSR-11 container or an HTTP middleware pipeline. `queue-cycle` depends directly on `cycle/database`, not the higher-level `componenta/cycle` ORM package. Composition selects instances and destinations. There is no global current queue, tenant, connection or handler.

Interfaces exist at real substitution boundaries. Values, `Worker`, registry and policy implementations are concrete classes; they do not each receive a matching interface. A delivery is a resource capability with a lifecycle, not a serializable DTO. Its provider receipt, token, row ID and native client remain private.

## 3. Shared invariants

- **I1 — Identity and ownership:** the message ID identifies logical work. A delivery identifies one reservation. Retrying or redelivering preserves message identity and bytes; it never reuses authority from an older reservation.
- **I2 — Recoverability:** settlement must not remove the source before a required retry/failure copy is accepted. The database profile performs the whole transition atomically. A future publish-before-ack profile may create duplicates, and must say so.
- **I3 — Uncertainty:** a known no-effect failure, lost ownership, and a possibly applied operation are distinct. An unknown acknowledgment never triggers an opposite disposition automatically.
- **I4 — Handling boundary:** only successful return from the handler permits acknowledgment. A codec failure or handler failure does not become success. An acknowledgment error does not become a handler error.
- **I5 — Scheduling:** a requested delay is honored as a minimum eligibility delay under the adapter's documented time authority, or rejected without publication/settlement. It is never silently ignored or implemented by sleeping inside the handler worker.
- **I6 — Bounded repetition:** scheduled retries have a finite configured budget. Crash recovery has a separate adapter delivery budget. Neither counter is advertised as an exact count of business effects.
- **I7 — Failure preservation:** the source wire bytes exist before decoding, and remain available for failure capture even when no valid `Message` can be constructed.
- **I8 — Isolation:** immutable values do not retain mutable array references; a handler receives no settlement authority; configuration and registrations do not change during an active delivery.
- **I9 — Mode parity:** optional source/prepared/warm App paths produce the same observable routes, decoding, policies, errors and handler outcomes.

Durable profiles target at-least-once delivery while their configured storage, retention and operating assumptions hold. Duplicate effects remain possible, including when a handler succeeds and the process dies before acknowledgment. There is no universal exclusion guarantee after a lease expires, and fencing a queue row cannot cancel an external HTTP side effect.

## 4. Data and wire format

### Public values

`Message`, `MessageType`, `Headers`, `RawMessage`, `DeliveryMetadata`, `LeaseSnapshot`, `FailureDetails`, failure read models and decision values are final immutable values. PHP `readonly` alone is insufficient for arrays containing references: construction reconstructs and validates string entries, breaking retained references. No mutable object, resource, closure or arbitrary PHP value is stored in headers.

| Value | Fields and meaning |
| --- | --- |
| `Message` | `id: string`, `type: MessageType`, `body: string`, `headers: Headers`. Body is opaque bytes, including an empty body. |
| `MessageType` | `name: string`, `schemaVersion: int`. Name is a stable application alias; schema version is a positive integer. |
| `Headers` | A validated `array<string,string>` snapshot. Header names are lower-case ASCII identifiers matching `[a-z][a-z0-9_-]*`; values are valid UTF-8. Numeric-only names are rejected rather than being coerced into integer PHP array keys. |
| `RawMessage` | `body: string` containing exact wire bytes and `headers: Headers` containing the adapter's mapped string headers. Construction does not parse the body. It does not pretend to preserve unmapped provider-native objects. |
| `DeliveryMetadata` | `source: string` (configured non-secret destination label), `retryCount: int`, `deliveryCount: ?int`, `countAccuracy: CountAccuracy`, `receivedAt: DateTimeImmutable`. No receipt or authorization credential. |
| `LeaseSnapshot` | `expiresAt: DateTimeImmutable`, an informational snapshot under the adapter's clock authority. It is not a portable proof that the caller still owns the message. |
| `FailureDetails` | `reason: FailureReason`, `exceptionType: ?string`, `description: ?string`. Reasons are `Decode`, `Handler`, `Rejected`, `DeliveryLimit`. Description is optional explicitly selected diagnostic text. Exception type is 1–255 UTF-8 bytes without control characters; description is at most 2048 UTF-8 bytes without NUL. Both may be null. |

`CountAccuracy` is `Exact`, `Approximate` or `Unknown`. Unknown requires `deliveryCount = null`; other values require a positive count. Exact means reservations in the current processing run, including claims followed by a crash before the handler starts. `retryCount` starts at zero and increments only when a scheduled retry transition is durably accepted. Native recovery/redelivery does not increment it. Administrative redrive starts a new processing run, while retaining historical failure/redrive records.

IDs are non-empty UTF-8 strings without control characters, compared byte-for-byte. UUID is recommended at the application boundary, not required by the core. Type names match `[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*`. Names, schema versions and headers do not select executable PHP classes. `componenta-` header names are reserved for library-owned extensions and are rejected from application `Message` headers. Trace/correlation headers are data, not authentication.

`MessageLimits` is explicit immutable configuration with positive `maxBodyBytes`, `maxWireBytes`, `maxHeaderBytes`, and `maxJsonDepth`; JSON depth is in `3..2147483647`, using PHP's JSON parser depth convention and native upper bound. Sizes are byte counts. Header size is the sum of name and value byte lengths, checked independently for application and mapped transport headers. Wire size is raw body bytes plus mapped transport-header name/value bytes; it does not estimate protocol-specific framing. Adapter-native limits may be tighter and are checked before side effects. There is no truncation. The application chooses limits for its workload; the specification invents no capacity default.

### Codec boundary

```php
interface MessageCodecInterface
{
    public function encode(Message $message): RawMessage;
    public function decode(RawMessage $message): Message;
}
```

The codec is pure: no container lookup, class discovery, network I/O, message publication or time generation. `encode` validates limits before publisher I/O. `decode` runs only after `receive` returned a delivery. Expected invalid input throws `DecodingException`; a programming error in the codec is an infrastructure failure, not evidence that the message is malformed.

The initial concrete codec is `JsonMessageCodec`. Its version 1 envelope is:

```json
{
  "format": "componenta.queue",
  "version": 1,
  "id": "01912345-6789-7abc-8def-0123456789ab",
  "type": "invoice.generate",
  "schemaVersion": 1,
  "headers": {"content-type": "application/json"},
  "bodyBase64": "eyJpbnZvaWNlSWQiOjQyfQ=="
}
```

The wire content type is `application/vnd.componenta.queue+json;version=1`. Encoding sets this as the sole mapped transport `content-type` header; decoding requires its exact value and rejects a missing or different value. Other mapped string headers do not change decoding semantics and remain subject to the raw header budget. All seven fields are required; `headers` is an object even when empty. Version fields must be integers without overflow or numeric coercion. The body uses canonical padded RFC 4648 base64; decoding is strict and preserves arbitrary bytes. The producer writes keys in the shown order and sorts header names bytewise. This determinism supports the database profile's byte comparison; it is not a general JSON canonicalization standard or a signature scheme.

The decoder accepts insignificant JSON whitespace and member order differences, but rejects duplicate member names, missing/unknown envelope fields, unsupported format/version, non-string header values, invalid base64, invalid UTF-8 in string fields and exceeded bounds. Duplicate-name rejection needs explicit parser/tokenizer validation; `json_decode()` alone is not sufficient. The envelope depth is bounded before unbounded object construction. No PHP `serialize`/`unserialize`, closure serialization, FQCN autoloading, automatic compression or arbitrary constructor execution is part of the codec.

An unsupported business schema is different from an unsupported wire version. The worker's handler registry resolves the exact `(type.name, type.schemaVersion)` pair before any application object is constructed. Unknown pairs produce a permanent rejection. Explicit custom codecs can support a different wire format; there is no automatic sniffing of legacy CQRS, CloudEvents or arbitrary JSON.

## 5. Transport contracts

Each publisher, receiver and failure store is bound to one configured destination. Multiple queues are composed explicitly using different instances. Routing belongs to application composition or the CQRS bridge. Source labels and queue names are not derived from untrusted headers.

```php
interface PublisherInterface
{
    public function publish(Message $message, int $delayMilliseconds = 0): void;
}

interface ReceiverInterface
{
    public function receive(int $waitMilliseconds = 0): ?DeliveryInterface;
    public function close(): void;
}

interface DeliveryInterface
{
    public function raw(): RawMessage;
    public function metadata(): DeliveryMetadata;
    public function lease(): ?LeaseInterface;
    public function acknowledge(): void;
    public function retry(int $delayMilliseconds): void;
    public function fail(FailureDetails $failure): void;
}

interface LeaseInterface
{
    public function renew(int $leaseMilliseconds): LeaseSnapshot;
}
```

The two primitive timing arguments express independent operation settings with explicit units. They do not need a mutable options bag or stored per-call service state. Delay and wait are non-negative integers, renewal is positive. Overflow and adapter-specific maxima are rejected without applying the operation. Future unrelated options require a deliberate API review.

### Publication

Normal return means acceptance at the adapter's documented confirmation boundary; it does not mean handling is complete. Durable adapters require durable acceptance. The explicitly non-durable in-memory adapter only promises process-local acceptance. No API returns a newly invented message ID because the caller already supplied the logical ID.

The base publisher does not promise deduplication. Reusing an ID after an indeterminate publish can produce duplicates. The Cycle profile adds bounded idempotent publication and same-ID/different-content conflict detection. A confirmed broker acceptance with no routed destination cannot satisfy a durable destination publisher; a future adapter must check routing as well as confirmation.

Delay starts at the adapter's acceptance/scheduling linearization point under its authoritative clock, not the caller's PHP wall clock. Eligibility can occur later because of load. A positive delay never becomes an immediate publish as a silent fallback.

### Receiving and resource lifecycle

`receive` acquires at most one delivery. Zero wait performs one bounded poll; positive wait permits waiting up to the requested polling budget, subject to separately configured finite I/O deadlines. It may return sooner. `null` means no delivery was acquired in this poll, including contention; it is not proof that the whole queue is empty. A transport failure is an exception, never `null`.

A receiver owns its consumption session and allows one outstanding delivery at a time in v1. Calling `receive` while its previous delivery is active is a state error. Settlement makes the slot available; a lost or indeterminate delivery terminates that session, so recovery requires a new receiver. Calls are not reentrant or fiber-safe unless a later profile explicitly says so.

`close` is idempotent, cancels the consumption session, and never acknowledges unfinished work. It invalidates local delivery/lease capabilities before attempting fallible resource cleanup; durable recovery follows the adapter's lease/connection rules. A cleanup failure cannot make the local session usable again. Further `receive` calls fail with `ReceiverClosedException`. Adapters must distinguish owned sessions from borrowed physical connections and must not close an application-owned shared connection. Destructors do not silently acknowledge work and cannot be required for correctness.

### Settlement and local states

Delivery accessors return snapshots without I/O. The lease object, if present, is an intentionally live restricted capability; retaining it past settlement does not extend its validity. It exposes no acknowledgment, message replacement or native client.

| Local state | Allowed mutation | Outcome |
| --- | --- | --- |
| Active | acknowledge / retry / fail / renew | According to the operation below |
| Settled | None | `DeliveryStateException`, before I/O |
| Lost | None | `DeliveryLostException`, before I/O |
| Indeterminate | None | `IndeterminateOperationException`, before I/O |
| Closed by receiver | None | `DeliveryStateException`, before I/O |

- `acknowledge` completes this reservation after successful handling. It does not guarantee that no duplicate message will ever arrive.
- `retry` accepts a successor eligible no earlier than the requested delay, with identical logical message/raw payload and `retryCount + 1`, then relinquishes the current reservation. It performs no handler call or waiting. The adapter owns the transition and counter, not mutable application headers.
- `fail` preserves original raw bytes, available message metadata and bounded diagnostics in configured failure storage, then ends normal delivery. It works without decoding a valid `Message`.
- `renew` changes the same lease without incrementing either counter. It cannot recreate a lost, settled or expired lease under the strict database profile.

For a future non-atomic adapter, retry/failure may be implemented as accepted copy followed by source acknowledgment. Partial failure may leave both copies. It must never acknowledge first, never represent an unconfirmed failure copy as saved, and never advertise atomicity. A native broker `reject` is insufficient unless the configured dead-letter behavior satisfies these guarantees. An adapter that cannot provide the managed retry/failure contract is not a conforming implementation of this `DeliveryInterface`.

### Errors and uncertainty

Use a small domain exception family rather than leaking PDO/SDK exceptions as required catch types:

| Exception / classification | Caller meaning |
| --- | --- |
| `InvalidMessageException`, invalid argument | Invalid value or bounded input; no transport side effect |
| `UnsupportedFeatureException` | Requested operation/range is unsupported; no side effect |
| `TransportException` with `OperationOutcome::NotApplied` | Operation is proven not to have applied; cause retained |
| `IndeterminateOperationException` | Operation may have applied, or a multi-step transition may be partial; cause retained |
| `DeliveryLostException` | Current reservation no longer authorizes the operation |
| `DeliveryStateException`, `ReceiverClosedException` | Local lifecycle misuse, rejected before I/O |
| `MessageConflictException` | The stronger idempotent profile retained this ID with different immutable publication content |
| `FailureStateConflictException` | Administrative redrive/discard conflicts with an earlier terminal administrative decision |

`TransportException` exposes an immutable `outcome: OperationOutcome` with `NotApplied` or `Indeterminate`. `IndeterminateOperationException` is its specialized subtype with outcome fixed to `Indeterminate`. The separate lost-ownership and local-state errors remain distinguishable; callers do not need to inspect PDO or SDK exception types.

Unknown commit/confirmation outcomes are classified conservatively. There is no automatic failover to another queue after acceptance may have happened. In the direct delivery API, a delivery remains active after a proven no-effect settlement failure, so its owner may explicitly recover. The standard worker instead closes its receiver on an infrastructure exit, as specified below. Indeterminate or lost ownership invalidates the local mutation capability and consumption session. A low-level failure keeps its original `Throwable` as cause. Do not infer safe retry solely from an exception named timeout.

## 6. Worker and application processing

```php
interface MessageHandlerInterface
{
    public function handle(Message $message, ProcessingContext $context): void;
}

interface RetryPolicyInterface
{
    public function decide(FailureContext $failure): RetryAfter|Fail;
}
```

`ProcessingContext` exposes only the immutable `DeliveryMetadata` and nullable `LeaseInterface`. It never exposes `DeliveryInterface`, `ReceiverInterface`, the failure store or the broker receipt. Handlers return normally for success or throw; they cannot acknowledge success before their side effects complete.

`FailureContext` groups a decoded message, delivery metadata and the original handler `Throwable`. `RetryAfter` holds a validated delay in milliseconds; `Fail` requests terminal failure capture. The policy performs no I/O and does not mutate the message. `PermanentMessageException` is an explicit handler rejection and bypasses retry policy.

The standard bounded policy is `LimitedRetryPolicy(maxRetries, shouldRetry, backoff)`. `maxRetries` is a required non-negative integer, the classifier is an explicit callable, and backoff is a concrete configured calculation. `retryCount >= maxRetries` always yields `Fail`; the original delivery is not counted as a scheduled retry. Every custom `RetryPolicyInterface` implementation must also have a documented finite configured budget and obey this stopping rule; an unbounded policy is not conforming. `NoRetryPolicy` is available explicitly. There is no undocumented default retry count or implicit retry of all `Throwable` instances. Under a non-atomic future transport, this bounds each retry chain, not the number of all duplicated executions across chains.

`ExponentialBackoff` uses required non-negative initial and maximum millisecond delays and a finite multiplier of at least one. Zero is valid; an initial delay above the maximum is capped immediately. For retry ordinal `n = retryCount + 1`, calculate the capped exponential interval without integer overflow, then use full jitter in the inclusive range from zero to that cap. Randomness is supplied as a bounded integer callable for deterministic tests. Clamping happens before overflow; invalid random-source results are policy failures. The worker never sleeps to realize this delay.

`Worker` is a concrete orchestrator. Its constructor explicitly accepts a receiver, codec, handler, retry policy and PSR-3 logger (a `NullLogger` is acceptable). Five meaningful dependencies are preferable to a container or dependency bag. Its entry point is `processOne(int $waitMilliseconds = 0): ProcessingResult`; there is no second message bus or compulsory abstract job class.

`ProcessingResult` is an enum: `Idle`, `Acknowledged`, `RetryScheduled`, `Failed`. These names refer to the completed queue step. `Idle` means no delivery reached the worker; an adapter may have completed bounded recovery maintenance during that poll. In particular, `Failed` means failure capture succeeded, not merely that the handler threw. Infrastructure failures are exceptions, not additional success-looking result variants.

### Required processing order

1. Receive at most one delivery. Return `Idle` only when none was acquired.
2. Decode its raw snapshot. An expected `DecodingException` captures `FailureReason::Decode` using the still-available raw delivery; no handler is invoked. Unexpected codec failures leave the message recoverable and escape as processing infrastructure errors.
3. Invoke the configured handler. `HandlerRegistry` is an optional concrete handler that resolves exact alias/schema registrations; duplicates are rejected at composition, and unknown pairs throw `PermanentMessageException` before a domain decoder/constructor runs. There is no fallback to a wildcard, FQCN or another version.
4. Check the per-step lease-operation outcome before selecting any disposition. The context's restricted lease capability records failed renewal operations; a later successful renewal cannot clear a recorded failure. Any renewal failure, including `NotApplied`, lost ownership or an indeterminate result, is an infrastructure exit: do not call retry policy, acknowledge, retry or fail. This rule also applies when the handler caught the renewal exception and subsequently returned or threw a different error. It cannot stop external effects the handler continued to perform.
5. After successful handler return with no lease failure, acknowledge outside the handler exception-catching block. Return `Acknowledged` only after the acknowledgment call succeeds.
6. On an ordinary handler exception with no lease failure, ask the retry policy. On a permanent rejection, choose terminal failure directly. Execute exactly the chosen retry/failure transition. Return the corresponding result only after acceptance.
7. On every infrastructure exception, close the receiver before propagating the error. Never use a disposition as cleanup, and never reuse that worker/session after this exit; recovery creates a new receiver. Preserve the initial error if close also fails. Drop per-delivery references on every exit. Observational logging cannot alter settlement.

`ProcessingException` exposes `processingError: ?Throwable`, `infrastructureError: Throwable` and `closeError: ?Throwable`. It preserves a handler error alongside a policy, renewal or settlement error, and preserves an additional receiver-close error. The infrastructure error remains its exception cause. If only receiving or acknowledgment failed and close succeeded, propagate that original error without an unnecessary aggregate. Assertions inside handlers can be caught as handler errors, so tests inspect invocation evidence outside those handlers.

Application scope cleanup is owned by an App/handler decorator and completes before that decorator returns to the worker. A cleanup error therefore prevents acknowledgment and is explicitly a potentially duplicate-producing application failure. Observational logger/metrics errors are contained and never choose retry or failure after success. No mutable tenant, user or request state is copied into the next delivery.

### Long-running work and shutdown

The core step does not install signals, sleep between empty polls or kill handlers. `queue-console` owns the loop, monotonic elapsed-time measurement, idle waiting, process limits and SIGTERM/SIGINT flag handling. It stops starting new polls after observing stop, completes an already acquired delivery, then closes the receiver. A receive already in progress may return one delivery, which is treated as in flight and drained. Supervisor termination can still interrupt this sequence; durable recovery must not depend on graceful shutdown.

A worker runtime limit means stop acquiring subsequent work. It is not a hard handler timeout. Signals only set flags; they do not perform SQL or acknowledgment reentrantly. A synchronous PHP handler can renew through the context between chunks; automatic background renewal while arbitrary PHP code blocks is not promised. Configure lease and supervisor grace with the actual workload. Losing a lease cannot undo external effects already performed.

## 7. Failure inspection and recovery

```php
interface FailedMessageStoreInterface
{
    public function find(string $failureId): ?FailedMessage;
    public function page(FailureQuery $query): FailurePage;
    public function redrive(string $failureId, int $delayMilliseconds = 0): void;
    public function discard(string $failureId): void;
}
```

The store is bound to one destination. `FailureQuery` has a required positive limit, optional opaque cursor and optional `FailureStatus` filter. The initial statuses are `Pending`, `Redriven` and `Discarded`. Ordering is ascending stable storage sequence; the cursor is scoped to this store, ordering and filter. Invalid or cross-scope cursors are rejected. Pagination is a live view with keyset continuation, not a repeatable snapshot; concurrent status changes may change membership. No unbounded iterable is exposed by default.

`FailurePage` contains bounded summaries plus a nullable next cursor. A summary contains `failureId`, source label, optional logical message ID, failure time/reason and status; it omits the body. `find` adds the exact `RawMessage`, delivery snapshot and bounded details. A missing ID returns `null` from `find`; mutating a missing ID throws `FailureNotFoundException`. Backend failure is never presented as absence.

Redrive preserves logical ID and raw bytes, starts a new processing run with retry/delivery counters reset, and records that this failure was redriven. Same failure ID and same redrive parameters are idempotent; different parameters after redrive conflict. Redriving a discarded record conflicts. Repeated discard is idempotent, but discard after redrive conflicts and cannot cancel the active new run. Redrive is explicit even when the body is malformed; the operator may inspect or migrate the format separately. It never silently edits the original record into a different message.

Discard removes eligibility for replay, not an arbitrary new live delivery. Failure history and payload cleanup follow explicit retention settings. Pending failures are never automatically re-executed or deleted because an unrelated deduplication period elapsed. Core commands have no end-user permission model: the application/CLI must authorize administrative access and avoid printing sensitive bodies by default.

## 8. Durability, transactions and security

The application's business database transaction and an external broker publication are separate effects. After-commit publication avoids premature reads but leaves a crash window. For a local database queue, an explicit transactional staging API can insert the row on the same physical connection and transaction as the business changes. For an external broker, an outbox relay publishes committed rows before marking them sent; a crash can duplicate publication. Neither behavior is hidden inside the base publisher.

An inbox can make local business updates idempotent when its unique `(consumer_scope, message_id)` marker commits in the same transaction as those updates. A separately committed marker does not provide that guarantee. External payment/email APIs need their own idempotency/reconciliation mechanism. The initial queue does not impose a generic inbox interface or claim that a mutex solves this problem.

Broker credentials and database roles limit who can publish, consume or inspect failures. Incoming tenant/user headers are not trusted authority. The application validates scope and current authorization for the work it executes. Do not serialize an authenticated session, permissions snapshot, service container, callback or secret into the message by default. Transport confidentiality/authentication belongs to the adapter configuration.

Default failure diagnostics retain the category and exception class, without arbitrary exception messages, serialized exceptions or stack traces. Applications may opt into a bounded redacted description. Logging uses source/type/outcome and durations with bounded metric labels; message IDs and payloads are not metric dimensions. Messages are not deleted or acknowledged because logging failed.

## 9. Testing utilities, performance and extension policy

The in-memory adapter uses the same codec and explicit clock, enforces the same local lifecycle and scheduling rules, and clearly documents that process exit loses its data. It does not claim cross-process durability. A separate recording publisher is a test double for publication intent; it does not execute handlers and is not a database-queue substitute.

Use decorators around publication/handling and explicit composition for current extension needs. Add no mandatory plugin registry, generic event system, container-aware factory or middleware protocol just to support logging. Optional integrations can use PSR-14 without making event dispatch part of the settlement transaction.

Performance acceptance measures codec and worker CPU/memory separately from confirmed publication, claim, settlement and end-to-end database latency. Use phpbench for isolated costs and concurrent integration workloads for contention/backlog behavior. Record environment, payload size, durability settings and distributions. There is no invented throughput target and no optimization that weakens I1–I9. Long handlers do not retain database row locks; initial prefetch is one; resources and polling are bounded.

## 10. Example call sites

These examples assume configured instances and proposed classes; they are not executable installation instructions. The example delays are application choices, not library defaults.

```php
$publisher->publish(new Message(
    id: $applicationGeneratedId,
    type: new MessageType('invoice.generate', schemaVersion: 1),
    body: json_encode(['invoiceId' => 42], JSON_THROW_ON_ERROR),
    headers: new Headers(['content-type' => 'application/json']),
));

$publisher->publish($message, delayMilliseconds: 30_000);
$result = $worker->processOne(waitMilliseconds: 1_000);

// Administrative authorization belongs to the calling application.
$failed = $failures->find($failureId);
$failures->redrive($failureId, delayMilliseconds: 60_000);
```

```php
final class ImportHandler implements MessageHandlerInterface
{
    public function handle(Message $message, ProcessingContext $context): void
    {
        // Decode through a registered, allowlisted application codec.
        // Commit idempotent business work in bounded chunks.
        $context->lease?->renew(leaseMilliseconds: 120_000);
    }
}
```

The import snippet illustrates the restricted lease capability only; it does not implement an import or establish safety when the adapter has no renewable lease. A handler that requires renewal must validate that capability at composition/startup rather than assume a nullable lease will be present.

## 11. Implementation completion

The ordered slices and proof map are in [verification](verification.md). Before a release, compare the implementation with all normative boundaries above and the selected adapter profile, not only with passing tests. Resolve missing, contradictory or unrequested behavior. Expanding to another broker/database requires its own operating profile and real conformance evidence; matching these PHP signatures is insufficient.
