# Queue research and design rationale

Sources accessed: **2026-11-27**.

This document explains the evidence behind the proposed Componenta queue library.
The [specification](specification.md) is the authority for public contracts and
observable behavior. The [Cycle profile](cycle-profile.md) defines the first
database adapter, [integrations](integrations.md) defines package boundaries, and
[verification](verification.md) defines implementation acceptance. This delivery
contains a design, not an implemented or benchmarked queue runtime.

## 1. Scope and selection criteria

The target is background work with competing consumers: email, webhooks, imports,
document generation, and asynchronous commands. The useful common boundary is
reliable delivery and processing of messages. A command bus, application
container, broker administration console, event log, and workflow engine solve
different problems and need not become core dependencies.

The MVP therefore consists of an independent core and a Cycle database adapter,
with PostgreSQL as its first explicitly supported profile. CQRS integration and
App/Console integration remain separate. Other transports can follow after the
portable contract has been exercised against the database implementation. This
does not declare a database universally faster or more reliable than a broker;
it limits the first implementation to a concrete, testable operating model.

The comparison below evaluates ownership, failure behavior, extension boundaries,
and verification. Feature counts and framework popularity are not selection
criteria. Recommendations are project decisions unless a cited standard actually
requires them.

## 2. What existing libraries demonstrate

### Symfony Messenger

Messenger combines synchronous message handling with asynchronous transports.
Its transport extension separates sender and receiver roles; the receiver
retrieves messages and exposes acknowledgment and rejection operations.
The documented model also includes configurable retries and failure transports.
Sources: [Messenger](https://symfony.com/doc/current/messenger.html) and
[custom transports](https://symfony.com/doc/current/messenger/custom-transport.html).

The useful lesson is separation: transport I/O, application handling, and retry
policy can evolve independently. Componenta should retain that distinction
without requiring another message bus alongside CQRS or copying the complete
envelope/stamp mechanism. Transport-specific receipts should remain with the
delivery that owns them, rather than becoming unrestricted application metadata.

The current documentation also describes service-state reset in long-running
workers, recoverable handling of decoding failures, and an in-memory transport
whose serialization option defaults to false. Those details motivate explicit
cleanup, a failure path that retains raw bytes, and codec tests independent of a
recording fake. The pages use the `current` selector and contain version-specific
notes, including 8.1; this research does not infer a latest stable release number.

### Laravel Queue

The Laravel **13.x** documentation presents jobs, middleware, retries, failed-job
management, and queue fakes. It distinguishes the worker timeout from the period
after which an unfinished job becomes eligible for another attempt. It also
documents dispatch after transaction commit and cancellation of deferred jobs
when that transaction rolls back.
Source: [Laravel Queues](https://laravel.com/framework/docs/13.x/queues).

These are useful operating concerns, but they need not require an active Job
object, facade, ORM-model serialization, or handler-controlled acknowledgment.
Componenta can offer ordinary handler objects and constructor-injected services.
Policies should explain whether initial execution counts toward an attempt limit
and which failures consume an attempt.

After-commit dispatch and transactional publication are different design choices.
Waiting for commit prevents a consumer from reading uncommitted application data.
A crash after that commit but before a separate publication still leaves a gap.
Closing that gap requires the queue insertion or an outbox record to participate
in the same transaction; a callback alone does not establish that guarantee.
This is an inference from the operation ordering, not a Laravel durability claim.

### Spiral Queue

The examined Spiral documentation identifies **3.16**. Its producer accepts a job
name, payload, and options. It documents registered handlers, selectable
serialization, and a retry policy coordinated through consumption interceptors.
Its testing support records submitted jobs without executing them.
Sources: [job handlers](https://spiral.dev/docs/queue-jobs/current/en) and
[queue testing](https://spiral.dev/docs/testing-queue/current/en).

Componenta should adopt explicit message types, cohesive operation options, and
separate tests for dispatch and execution. It should not require an abstract job
base class or make a PHP class name the permanent external message identity.
Renaming application code should not silently redefine the accepted wire format.
Middleware can be useful, but a general interceptor framework should not be a
mandatory dependency merely to support logging or timing.

### RoadRunner Jobs

RoadRunner provides producer and consumer APIs around its server runtime. The
payload is a string and application code supplies serialization. Received tasks
have completion and failure operations. Its documentation warns that time spent
waiting in the internal queue must fit the broker's acknowledgment deadline;
recovery depends on the driver and acknowledgment settings. The memory driver
loses work when RoadRunner stops.
Source: [RoadRunner Jobs](https://docs.roadrunner.dev/docs/queues-and-jobs/overview-queues).

This supports a future adapter, not a mandatory runtime dependency. It also shows
why prefetch is a correctness setting as well as a throughput setting: already
reserved messages can become eligible for redelivery before a PHP handler starts.
Componenta should begin with bounded acquisition and a simple worker lifecycle.
Parallel execution and large buffers require additional evidence about ownership,
shutdown, and deadline extension. The reviewed page does not establish a package
release version.

### Enqueue and queue-interop

Enqueue distinguishes transport and consumption layers. The transport has
connection factories, contexts, producers, consumers, and messages; processors
return acknowledgment, rejection, or requeue decisions. Queue-interop explicitly
states that its work is not officially endorsed by PHP-FIG.
Sources: [concepts](https://php-enqueue.github.io/concepts/),
[processors](https://php-enqueue.github.io/consumption/message_processor/), and
[queue-interop](https://github.com/queue-interop/queue-interop).

The role separation is valuable, and an interoperability adapter could be useful
later. Adopting its entire object model would also expose a broad context and
mutable transport representation to clients. Componenta should keep narrower
capabilities where a handler only needs message data. Signature compatibility
alone cannot prove that two implementations agree on rejection, durability,
delays, or recovery. An adapter must document those differences explicitly.

## 3. Standards: applicable layers

The [PHP-FIG PSR index](https://www.php-fig.org/psr/) contains no accepted queue
PSR at the access date. Queue-interop is a community interoperability effort,
not such a PSR. Existing standards still cover useful dependencies:

| Standard | Application to Componenta | Boundary |
| --- | --- | --- |
| [PSR-20](https://www.php-fig.org/psr/psr-20/) | Inject wall-clock access for timestamps and deterministic tests. | It does not specify monotonic elapsed time or database lease authority. |
| [PSR-3](https://www.php-fig.org/psr/psr-3/) | Accept an interoperable logger where logging is required. | Logging success does not establish message persistence. |
| [PSR-4](https://www.php-fig.org/psr/psr-4/) | Use conventional namespace-to-file autoloading. | It does not define external message names. |
| [PSR-12](https://www.php-fig.org/psr/psr-12/) | Establish a shared coding-style baseline. | It does not determine architecture or queue semantics. |
| [PSR-14](https://www.php-fig.org/psr/psr-14/) | Optionally integrate local lifecycle notifications. | Its event-dispatch contract is not a durable queue protocol. |

[OASIS AMQP 1.0](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-overview-v1.0-os.html)
defines a wire protocol and messaging layers. It is not a PHP interface
specification, and adopting its vocabulary does not make an unrelated database
driver AMQP-compliant. A future AMQP adapter must name the protocol and client
profile it actually implements.

[CloudEvents 1.0.2](https://github.com/cloudevents/spec/blob/v1.0.2/cloudevents/spec.md)
defines event data and context conventions.
[AsyncAPI 3.0.0](https://www.asyncapi.com/docs/reference/specification/v3.0.0)
describes message-driven application APIs in a machine-readable form. Both can
support interoperability later: CloudEvents through an explicit encoding profile,
AsyncAPI through documentation and schema tooling. Neither supplies worker
execution, acknowledgments, retries, or transactional persistence. The cited
versions are the versions inspected, not claims about the latest releases.

Useful RFCs apply to particular data formats:
[RFC 8259](https://www.rfc-editor.org/rfc/rfc8259) for JSON,
[RFC 9562](https://www.rfc-editor.org/rfc/rfc9562) for UUIDs, and
[RFC 3339](https://www.rfc-editor.org/rfc/rfc3339) for timestamp representation.
Their relevance does not justify inventing a universal queue RFC. Exact accepted
encodings and limits belong in the specification.

## 4. Why the first database profile is deliberately narrow

[Cycle's 2.x transaction documentation](https://cycle-orm.dev/docs/database-transactions/current/en)
exposes transaction management and notes that automatic reconnection is attempted
only outside an open transaction. The adapter must therefore treat connection
failure during a transaction as a meaningful failure boundary; reconnecting and
continuing cannot be assumed equivalent to the interrupted transaction.

[PostgreSQL 16 SELECT documentation](https://www.postgresql.org/docs/16/sql-select.html)
describes `SKIP LOCKED` as useful for multiple consumers accessing a queue-like
table, while warning that it provides an inconsistent view for general queries.
That is evidence for a reservation strategy, not proof of a complete queue.

The proposed profile must additionally establish atomic claim transitions,
reservation expiry, stale-owner protection, retry scheduling, and failure storage.
Business handlers should not hold row-locking transactions open for their entire
execution. Transactional enqueue requires the actual transaction and connection
to be shared; using the same database name is insufficient. These choices and
their SQL details are specified in [the Cycle profile](cycle-profile.md).

Supporting Cycle does not automatically certify every database backend supported
by its abstraction. PostgreSQL is the first profile; another backend needs its
own concurrency tests and documented guarantees.

## 5. Design recommendations and verification consequences

Separate immutable message data from the authority to settle one delivery. Keep
broker connections, reservation tokens, and internal retry bookkeeping outside
ordinary handler input. Use stable, registered message types and bounded codecs;
the core should not deserialize arbitrary PHP objects selected by incoming data.

Specify uncertainty instead of promising universal exactly-once execution. If a
handler completes but acknowledgment is lost, repeating delivery can be correct.
Application idempotency is therefore a real integration concern. Retry and
failure-storage transitions must preserve recoverability when their persistence
step fails. An optional capability must either work under its documented profile
or fail clearly, rather than being silently ignored.

Prefer a concrete worker orchestrator with explicit dependencies. Introduce
interfaces for independently replaceable I/O, processing, and policy boundaries,
not one interface for every class. Keep CQRS adaptation, container scopes,
signals, CLI commands, and application bootstrapping in their integration layers.

Implementation should proceed through the [verification plan](verification.md):
first a failing behavioral test, then the smallest implementation, then a review
against the same contract. Recording fakes verify publication intent; codec tests
verify bytes; real database tests verify competing consumers and crash recovery.
Neither an in-memory test nor PHP syntax validation demonstrates durability.
Performance work follows correctness and measures codec cost, worker overhead,
database operations, contention, and end-to-end throughput separately.

Deferred features include broker administration, general workflow orchestration,
distributed scheduling, cross-broker atomic transactions, and universal ordering.
They can be considered when a concrete consumer and operating profile justify
the additional public contract.
