# Full ecosystem review

Review date: **2026-11-27**. Scope: `componenta/queue`, `queue-cycle`,
`queue-console`, `cqrs-queue`, `queue-app`, and their combined consumer fixture.
This report records source review, executed checks and measurements. It does not
replace the [behavioral specification](specification.md) or establish a stable
release, a production service objective, or an exhaustive security guarantee.

The package boundaries remain appropriate for their responsibilities. The review
confirmed and corrected PostgreSQL schema installation failures for valid quoted
identifiers, and measured an idle-cleanup cost that justified a small query
optimization. The final cleanup implementation retains the original ordered
selection and locking behavior. A more extensive `UNION` rewrite was rejected
after its behavior under a fully due backlog was measured. No new exploitable
vulnerability was demonstrated in the reviewed scope.

The local suites comprise **452 tests and 1,485 assertions**, with PHPStan
at maximum level and formatting checks passing in all five packages. This
snapshot records checks completed before publication; hosted workflow results
must be read at their corresponding published commits. PostgreSQL 19 coverage
is configured; the local PostgreSQL results below were obtained on **17.12**.

## 1. Scope and source provenance

The review started from these repository revisions. The schema, cleanup and
benchmark-tool changes described below are subsequent changes from those
baselines; the raw workload reports also record source fingerprints.

| Package | Review baseline | Responsibility |
| --- | --- | --- |
| [queue](https://github.com/componenta/queue/tree/b8c08bb) | `b8c08bb` | Message model, codec, worker, policies, ports and in-memory transport |
| [queue-cycle](https://github.com/componenta/queue-cycle/tree/043ecdc) | `043ecdc` | PostgreSQL persistence, reservations, failure administration and maintenance |
| [queue-console](https://github.com/componenta/queue-console/tree/b2c419c) | `b2c419c` | Process supervision, signals and explicit failure-administration commands |
| [cqrs-queue](https://github.com/componenta/cqrs-queue/tree/f5fa49e) | `f5fa49e` | Declared asynchronous command publication and synchronous consumption |
| [queue-app](https://github.com/componenta/queue-app/tree/f3d4031) | `f3d4031` | Handler discovery/preparation, configuration and per-message DI scopes |

The upstream `componenta/app` dependency was also checked against `b37525bc`.
The review used the `design-software-interfaces`, `php-best-practices` and
`php-testing` skills, with regression-first development for confirmed behavioral
defects. These are review methods, not external certifications. The security
work consisted of source, trust-boundary, behavior and dependency review; no
managed external security scan is represented by this report.

## 2. Architecture and public interfaces

### Roles are separate without duplicating the worker

The core ports correspond to distinct authority and substitution boundaries.
Publishing, receiving a session, settling a delivery, renewing a lease, handling a
message, deciding a retry and administering failures are separate operations.
Adapters implement those roles rather than adding a second processing state
machine. Console drives the existing worker, CQRS supplies publication and
handling adapters, and App controls construction and message scope lifetime.
The [integration design](integrations.md) describes those dependencies.

| Area | Design judgment | Evidence and practical effect |
| --- | --- | --- |
| Core orchestration | Keep the concrete `Worker` and narrow collaborator ports | [Worker](../src/Worker.php) exposes one processing use case. Handler code receives a restricted lease, while settlement remains with the worker. |
| Values and signatures | Group values only when they share an invariant or lifetime | [DeliveryMetadata](../src/DeliveryMetadata.php) owns count accuracy and counters. Worker dependencies represent different roles; a dependency bag would obscure them. |
| PostgreSQL adapter | Keep transaction and reservation authority inside the adapter | [Store](https://github.com/componenta/queue-cycle/blob/main/src/Internal/Store.php) owns durable transitions; SQL details do not enter the core contracts. |
| Console | Separate one process run from one message operation | [WorkerLoop](https://github.com/componenta/queue-console/blob/b2c419c/src/WorkerLoop.php) owns limits and stop reasons; commands obtain sessions lazily and invoke explicit administration ports. |
| CQRS | Keep the command-to-message mapping explicit | [CommandRegistry](https://github.com/componenta/cqrs-queue/blob/f5fa49e/src/CommandRegistry.php), [EnqueueMiddleware](https://github.com/componenta/cqrs-queue/blob/f5fa49e/src/EnqueueMiddleware.php) and [QueueCommandHandler](https://github.com/componenta/cqrs-queue/blob/f5fa49e/src/QueueCommandHandler.php) define admission, publication and synchronous completion. |
| App | Confine container access to framework composition | [ScopedMessageHandler](https://github.com/componenta/queue-app/blob/f3d4031/src/ScopedMessageHandler.php) constructs and closes message scopes; the core and business handler contracts do not accept the application container. |

An interface was not added for every concrete class. Value objects, the worker,
registries and fixed policy implementations do not become more substitutable
merely by acquiring matching interfaces. Likewise, the five worker dependencies
are five meaningful collaborators, not evidence that they should be hidden in a
configuration object. This follows the interface-design skill's emphasis on
consumer roles and invariants. The treatment of the container is consistent with
the [PSR-11 meta guidance](https://www.php-fig.org/psr/psr-11/meta/) on service
location and object construction.

Readonly data is used with an explicit ownership model. Header and page arrays
detach caller-held references, timestamps are copied into immutable values, and
messages have stable aliases and schemas. This does not mean that every reachable
object is deeply frozen: a processing context intentionally contains a live
lease, a failure context can carry the original throwable, and registry handlers
and borrowed application services retain their identity. Deep-cloning those
services would change the intended semantics. See [Headers](../src/Headers.php),
[FailurePage](../src/FailurePage.php), [ProcessingLease](../src/Internal/ProcessingLease.php)
and the [App scope implementation](https://github.com/componenta/queue-app/blob/f3d4031/src/ScopedMessageHandler.php).

### What was deliberately left unchanged

No new core runtime abstraction or optimization was justified by this pass.
The in-memory transport remains a deterministic, same-process implementation.
Its historical identities support duplicate detection and failure administration;
silently discarding them to lower a memory measurement would change its contract.
There is no general promise of reentrant callbacks, concurrent fibers, global
FIFO ordering or automatic interruption of arbitrary PHP handler code. These
would require additional contracts and evidence, not assumptions derived from
the presence of interfaces.

## 3. Confirmed changes and stability review

### Quoted schema identifiers and installation atomicity

The schema installer previously split SQL after inserting quoted identifiers.
A permitted schema name containing `;` could therefore be split into invalid
statements. A second edge case involved names containing template markers:
sequential replacement could process marker text introduced by a previous
replacement. Both failures were reproducible without changing the allowed
identifier contract.

The [installer](https://github.com/componenta/queue-cycle/blob/main/src/Schema.php)
now splits the bundled static template first, then uses a single-pass `strtr`
mapping for each statement. Identifier quoting remains in the connection
adapter. This is deliberately a parser for the package's controlled template,
not a general SQL parser. DDL, indexes and the installation marker remain inside
one transaction.

[Schema regressions](https://github.com/componenta/queue-cycle/blob/main/tests/SchemaTest.php)
exercise semicolons, embedded quotes, embedded whitespace and both marker names
through installation and real queue operations. A pre-existing table forces an
installation failure after an earlier DDL statement, checking that confirmed
rollback removes newly created objects, preserves existing data and restores the
transaction level. An indeterminate commit or rollback outcome still requires
database inspection before retrying installation; transaction use alone cannot
establish what happened after a lost connection.

### Cleanup preserves its original authority and ordering

The selected cleanup optimization adds two independent existence checks before
the original candidate predicate. They check whether the configured queue has
any due terminal ledger or attached, resolved failure history. Both use the same
captured database-time bound as candidate selection. If neither exists, the
database can skip the ordered ledger scan.

The original `OR` predicate, `ORDER BY row_id`, operation limit and
`FOR UPDATE OF m SKIP LOCKED` remain in place. The guards acquire no row locks;
ledger locks still precede failure locks, and detached failure history keeps its
separate cleanup branch. The adapter's write paths maintain the matching queue
identity of linked ledger and history records. Arbitrary direct corruption of
those tables is outside the adapter contract. Review covered the final
[Store change](https://github.com/componenta/queue-cycle/blob/main/src/Internal/Store.php)
and [candidate regressions](https://github.com/componenta/queue-cycle/blob/main/tests/CleanupCandidatesTest.php),
alongside the existing retention and concurrency scenarios.

Concurrent work becoming visible after a selection snapshot can be handled by a
later cleanup call. The guard does not grant new settlement or deletion authority.
Its purpose is to avoid known no-work scans; it does not make every cleanup query
constant-time. The measured tradeoffs are recorded in section 6.

### Existing lifecycle protections were rechecked

Core processing retains one settlement authority, generation-aware ownership and
sticky lease-renewal failure. Poll, preparation, handling, retry policy,
settlement and session-close failures remain distinguishable. In-memory state
changes prepare fallible work before committing transitions. PostgreSQL checks
the lease token and database time while holding the relevant ledger lock;
transaction ambiguity is not reported as confirmed publication success.
See [Worker](../src/Worker.php), the [memory store](../src/InMemory/Internal/Store.php)
and the [persistent profile](cycle-profile.md).

Console review covered lazy session creation, limits checked around creation,
actual process signals, event cancellation and reused-command stop state.
Opaque failure IDs and cursors are forwarded exactly when they meet the command
contract; JSON output rejects invalid UTF-8 rather than substituting a different
identifier. CQRS checks the declared context before decoding, preserves the
original operation identity when publication is uncertain, and requires a
synchronously completed `OperationResult` before acknowledgment. A completed
result with a null business value remains valid. These are existing protections,
not additional defects claimed for this pass. See the
[Console input boundary](https://github.com/componenta/queue-console/blob/b2c419c/src/Internal/InputValues.php)
and [CQRS consumer](https://github.com/componenta/cqrs-queue/blob/f5fa49e/src/QueueCommandHandler.php).

App review covered exact handler-map semantics, prepared-map freshness,
protected runtime bindings, detachment of referenced configuration sections and
scope teardown. Cleanup callbacks run in reverse registration order, continue
after individual failures and preserve handling and cleanup causes. New cleanup
registration closes when teardown starts. Borrowed services remain shared by
design. These boundaries are implemented in
[HandlerMapFactory](https://github.com/componenta/queue-app/blob/f3d4031/src/HandlerMapFactory.php),
[ScopedMessageHandler](https://github.com/componenta/queue-app/blob/f3d4031/src/ScopedMessageHandler.php)
and [CleanupStack](https://github.com/componenta/queue-app/blob/f3d4031/src/Internal/CleanupStack.php).

## 4. Security boundaries and remaining responsibilities

| Boundary | Reviewed protection | Remaining responsibility or limit |
| --- | --- | --- |
| Wire input | Byte limits before JSON parsing, depth limits, exact field/type/version checks, duplicate-member rejection and canonical base64 | Limits bound codec work; they do not bound arbitrary application handler CPU, I/O or allocations. |
| Type selection | Explicit alias/schema registry; no wire-directed FQCN construction or native PHP object deserialization | Applications must keep registered codecs and handlers appropriate for their trusted composition. |
| Delivery authority | Restricted processing lease; settlement, fencing and current-generation checks inside the worker/transport | External effects still need application idempotency or an inbox when redelivery is possible. |
| Failure administration | Queue/status-scoped opaque cursors and generation-aware operations; exact IDs at CLI boundaries | Calling code must authorize inspection, redrive and discard. Queue headers do not establish tenant or user identity. |
| Database | Bound values, quoted identifiers, write-connection transactions and lock ownership checks | Database credentials, schema access, backup and transport security belong to deployment. The adapter is not a repair tool for manually corrupted records. |
| Error reporting | Default worker diagnostics avoid automatic payload and exception-message/trace capture | An application logger or explicitly supplied failure description can still disclose data if configured to do so. |
| Framework integration | Prepared maps are checked against current configuration; runtime scope services cannot be replaced by aliases/delegators | Build inputs and application configuration remain trusted. Shared application services must manage their own mutable state. |

The primary source anchors are the [JSON codec](../src/Codec/JsonMessageCodec.php),
[worker](../src/Worker.php), [memory store](../src/InMemory/Internal/Store.php),
[Cycle profile](cycle-profile.md) and integration sources linked above.
Avoiding native object deserialization is also consistent with the
[OWASP deserialization guidance](https://cheatsheetseries.owasp.org/cheatsheets/Deserialization_Cheat_Sheet.html).
It does not turn all application-specific decoding callbacks into trusted data
validators automatically.

Composer advisory checks covered the five package dependency graphs and the
combined consumer: **six graphs, no reported advisories** at the review snapshot.
The abandoned `doctrine/annotations` package remains in development-only
phpbench dependency paths, including the new App benchmark setup. Abandonment
is a maintenance concern and is distinct from a reported vulnerability or a
runtime dependency. The [dependency audit](evidence/full-review-2026-11-27/dependency-audit.json)
records the audit responses for the resolved graphs. An empty advisory result cannot establish
that unknown vulnerabilities do not exist.

## 5. Verification and TDD quality

| Suite | Tests | Assertions | Main evidence |
| --- | ---: | ---: | --- |
| Core, memory and portable memory contract | 247 | 590 | Wire rejection, retry policy, ownership, atomic transitions and failure administration |
| Cycle / PostgreSQL | 70 | 332 | Real transactions, locks, process death, uncertain outcomes, schema identifiers, rollback and cleanup candidates |
| Console | 60 | 256 | Lazy commands, limits, real signals, application events and exact administration identity |
| CQRS | 42 | 168 | Admission, original identity, publication uncertainty, preparation errors and synchronous completion |
| App | 29 | 71 | Source/prepared maps, runtime bindings, scope isolation, reference safety and cleanup |
| Combined consumer | 4 | 68 | All five packages on real PostgreSQL through source and prepared application composition |
| **Total** | **452** | **1,485** | Platform-matrix repetitions are not counted again |

The final Cycle guard passed **70/332**, PHPStan with no errors and formatting
checks with no proposed changes. The other package checks also passed at maximum
PHPStan level and under their formatting configuration. These are executed
local gates; the [implementation history](implementation.md) distinguishes
earlier hosted runs from current work.

Confirmed public failures received regression coverage before correction. The
schema tests exercise installation and queue behavior rather than matching SQL
strings. Cleanup tests cover overlapping reasons for selecting one ledger and
case-sensitive queue isolation; existing tests cover pending/current failures,
later live generations, locked records and concurrent maintenance. Performance
measurements are separate from tests and introduce no timing-threshold assertions.

The review also checked for false confidence in tests: swallowed handler errors,
assertions hidden inside callbacks caught by the worker, reflection over private
methods, and doubles that merely repeat an implementation. Deterministic clocks
are used for time-dependent behavior. Protocol-order spies are appropriate where
the ordering is public behavior; database and process integration tests exercise
the boundaries that doubles cannot establish. Test and assertion counts measure
executed scenarios, not a claim of complete state-space coverage.

PostgreSQL 19 was added to CI configuration after checking the
[official release announcement](https://www.postgresql.org/about/news/postgresql-19-released-3449/).
Configuration is not execution evidence: consult the published commit's
workflow jobs for its actual server versions and results.

## 6. Performance methodology and results

Heavy measurements within this audit were scheduled separately to avoid
competing audit workloads. The machine remained a shared host. PHP measurements
used PHP **8.5.13** with CLI opcache disabled; JIT was disabled. Results describe
the fixtures and instrumentation below, without a production latency, throughput
or concurrency guarantee.

PHPBench **modes** below are labeled separately from **medians** in the standalone
workloads and App summary. The shortest subjects have material relative standard
deviation (RSD); small differences between those subjects do not support a
ranking. No timing result became a unit-test threshold.

### Core CPU and memory

The retained PHPBench run used 100 warmup revolutions, 1,000 measured revolutions
and seven iterations. A shorter initial run was noisy, so this longer control is
the reported evidence. Source and raw samples are in
[core-phpbench.xml](evidence/full-review-2026-11-27/core-phpbench.xml).

| Subject | Mode, µs/op | RSD |
| --- | ---: | ---: |
| Encode, 256-byte body | 2.573 | 11.26% |
| Decode, 256-byte body | 12.255 | 9.09% |
| Encode, 64 KiB body | 185.954 | 9.21% |
| Decode, 64 KiB body | 240.424 | 5.66% |
| Memory publish → worker → acknowledgment | 81.216 | 6.25% |
| Exact handler-registry dispatch | 0.144 | 25.59% |
| Header operation, small fixture | 0.624 | 12.28% |
| Header operation, large fixture | 0.659 | 16.70% |
| Capped backoff, small retry ordinal | 0.437 | 21.51% |
| Capped backoff, large retry ordinal | 0.405 | 9.36% |

The memory round trip includes message construction/encoding, publication,
consumption, handler invocation and acknowledgment. It is not a broker or
database measurement. Codec cost grows with payload size; registry lookup and
the saturating backoff calculation did not require additional runtime changes.

The public scale probe used a frozen clock, 256-byte bodies, seven timing samples
and separate scenarios at 1,000 and 10,000 records. Each scenario was released
before the next. Retained heap is intentional state retained by that queue, not
an estimate of process RSS. Raw results:
[core-memory.json](evidence/full-review-2026-11-27/core-memory.json).

| State | Records | Retained PHP heap, bytes | Empty poll median, µs | First / last ten failures, µs |
| --- | ---: | ---: | ---: | ---: |
| Completed | 1,000 | 2,523,136 | 0.180 | — |
| Completed | 10,000 | 26,160,920 | 0.161 | — |
| Future/delayed | 1,000 | 2,497,792 | 20.351 | — |
| Future/delayed | 10,000 | 25,927,992 | 22.517 | — |
| Failed | 1,000 | 3,819,952 | 0.163 | 5.223 / 21.420 |
| Failed | 10,000 | 37,507,504 | 0.163 | 5.279 / 150.124 |

The bounded rotating poll avoids scanning the entire future backlog on each
call. In contrast, late failure pages traverse retained history: their cost is
O(N) in that history. Queue lifetime storage also grows with messages and failure
history. These are explicit limits of
[InMemoryQueue](../src/InMemory/InMemoryQueue.php), not evidence of bounded
memory for a permanently running in-memory broker.

### Console and CQRS

The Console fixture processes 2,000 messages per iteration over seven iterations,
with 256-byte bodies and no database, network or business work. Median cost was
**16.713 µs/message** for the direct core worker, **16.413 µs/message** with the
message-limit loop, and **16.883 µs/message** with all supported limits enabled.
These small differences are within the observed variation; the data supports
small loop overhead in this fixture, not a claim that supervision makes the
worker faster. See
[console-worker.json](evidence/full-review-2026-11-27/console-worker.json).

CQRS PHPBench used 1,000 revolutions and seven iterations:

| Subject | Mode, µs/op | RSD |
| --- | ---: | ---: |
| Synchronous unregistered command | 3.281 | 10.73% |
| Enqueue and wire validation | 10.039 | 10.43% |
| Admission, decode and synchronous dispatch | 4.965 | 28.06% |
| Reject unknown context before decode | 0.676 | 13.56% |

These are adapter CPU measurements, excluding durable publication confirmation
and application work. The rejection case also exercises the intended ordering
of admission before decoding. Raw samples:
[cqrs-phpbench.xml](evidence/full-review-2026-11-27/cqrs-phpbench.xml).

### App construction and scope lifetime

App measurements used the original audit harness. The portable benchmark files
were transferred into the repository afterward; their smoke validation is
separate from the recorded timing runs. The bootstrap samples use 50 revolutions
and five iterations; resolved-map lookup uses 10,000 revolutions. Scope samples
use 200 revolutions and five iterations.
The bootstrap fixture has 100 discoverable handlers whose PHP classes are
preloaded during setup. It measures fresh application/container composition,
not starting a new PHP process or loading uncached classes from disk. Reported
App medians are medians of iteration means.

| Subject | Median, µs/op | RSD |
| --- | ---: | ---: |
| Fresh source bootstrap | 3,457.740 | 10.54% |
| Fresh prepared bootstrap | 1,089.940 | 11.98% |
| Already resolved map lookup | 0.159 | 8.88% |
| Handle and clean, no extra borrowed services | 631.275 | 14.48% |
| Handle and clean, 100 extra borrowed services | 560.795 | 24.99% |
| Handle and clean, 1,000 extra borrowed services | 1,515.075 | 11.60% |

Prepared bootstrap reduced work for this configuration. Scope construction still
depends on the supplied dependency graph. The non-monotonic zero/100-service
medians and their variation do not establish a speedup from adding services.
Evidence: [app-bootstrap.xml](evidence/full-review-2026-11-27/app-bootstrap.xml)
and [app-scope.xml](evidence/full-review-2026-11-27/app-scope.xml).

The separate memory observation completed **10,200 scopes** with 100 extra
borrowed services and cycle collection at sample boundaries. Live heap moved
from **4,878,616** bytes after 200 scopes to **4,880,712** bytes after 10,200,
while allocated heap remained **10 MiB**. This is a bounded observation of that
fixture with explicit collection, not proof that arbitrary handlers, borrowed
services or applications are leak-free. See
[app-memory.json](evidence/full-review-2026-11-27/app-memory.json).

### Persistent queue workload

The PostgreSQL workload used one synchronous caller, PostgreSQL **17.12**,
`READ COMMITTED`, UTF-8, and `fsync`, `synchronous_commit` and `full_page_writes`
enabled. It processed 200 ready messages, 50 recovery workflows and 200 ready
messages behind 10,000 delayed messages, with 20 warmup operations and 256-byte
bodies. Percentiles use nearest rank. Business effects and multi-consumer
throughput were not measured.

| Public operation / workflow | p50, ms | p95, ms |
| --- | ---: | ---: |
| Ready publication | 1.363 | 2.406 |
| Ready claim | 1.464 | 2.377 |
| Ready acknowledgment | 1.252 | 1.823 |
| Ready publish → claim → acknowledgment | 4.210 | 6.237 |
| Retry → failure → redrive recovery workflow, including publication | 15.593 | 22.708 |
| Claim with 10,000 future records present | 1.460 | 2.151 |
| Idle poll with that future backlog | 0.331 | 0.430 |

The future records remained present after the ready drain. Source fingerprints
were unchanged over the run and the isolated benchmark schema was removed.
The report contains configuration, operation counts and plans:
[postgres-workload.json](evidence/full-review-2026-11-27/postgres-workload.json).

### Cleanup optimization: selection cost and public latency

The original predicate could scan many live/future ledger rows even when nothing
was eligible for cleanup. An initial `UNION` proposal found due candidates first,
but its deduplication and ordering work caused a substantial regression when
many records were due. The comparison below uses the **candidate SQL statement's
`EXPLAIN ANALYZE` execution time**, with 10,000 records, rotated variants, one
warmup and five measured samples. It does **not** measure the public cleanup call.

| Candidate-query scenario | Original OR, p50 ms | Rejected UNION, p50 ms | Chosen guard + OR, p50 ms |
| --- | ---: | ---: | ---: |
| All records future; nothing due | 10.513 | 0.034 | 0.026 |
| All records due | 0.026 | 8.574 | 0.043 |
| Ten due after 9,990 future records | 13.708 | 0.130 | 13.486 |

The chosen guard avoids the idle scan and preserves early stopping for a dense
due backlog. It deliberately leaves the sparse-due ordered-scan cost unresolved.
The third row is a real limitation: an operation budget bounds mutations, not
every row the database might inspect. The rejected rewrite is retained in the
[comparison evidence](evidence/full-review-2026-11-27/cleanup-candidates.json)
to document why its favorable idle result was insufficient.

The final public harness separately measured `cleanup(1)` with only future
records, using three warmups and 20 samples per size:

| Future records | Original p50, ms | Final guard p50, ms | Original p95, ms | Final guard p95, ms |
| --- | ---: | ---: | ---: | ---: |
| 100 | 0.455 | 0.367 | 0.695 | 0.777 |
| 1,000 | 1.251 | 1.090 | 1.683 | 1.443 |
| 10,000 | 10.056 | 0.506 | 10.791 | 0.683 |

At 10,000 future rows the public median improved about **19.9×** in this fixture.
The separately captured candidate plan reduced shared-buffer hits from
**10,229 to 2**, with zero executions of the guarded ledger scan. All measured
calls had zero cleanup effects and preserved the future backlog. The benchmark
file fingerprint was identical before and after, each source fingerprint was
stable during its run, and both isolated schemas were removed. Raw records:
[cleanup-before.json](evidence/full-review-2026-11-27/cleanup-before.json) and
[cleanup-after.json](evidence/full-review-2026-11-27/cleanup-after.json).

Both public runs include the same Cycle logger/interpolation instrumentation;
their absolute latency is not a logger-free estimate. The 100-row p95 increased,
and the 1,000-row result is noisy. The strong 10,000-row idle improvement must not
be generalized to all retention distributions, concurrent workloads or database
installations.

## 7. Reproduction and remaining limits

The repository tools expose the measured operations without adding runtime APIs
or performance thresholds:

| Package | Entry points |
| --- | --- |
| Core | `composer bench`; `php -d opcache.enable_cli=0 benchmarks/MemoryScaling.php` |
| Console | `composer bench` / `benchmarks/WorkerLoopWorkload.php` |
| CQRS | `composer bench` / `benchmarks/BridgeBench.php` |
| App | `composer bench`; `composer bench:memory` |
| Cycle | `benchmarks/QueueWorkload.php`; `benchmarks/CleanupWorkload.php` with the documented benchmark connection settings |

Use the sampling parameters in the raw XML/JSON when comparing with this report;
package defaults need not match every audit override. Database workloads create
and remove their own isolated schema and check public effects. Their plans are
collected outside timed public calls. The standalone core and App tools were
made portable after the original audit probes; a later smoke run is not a
repetition of the original performance experiment. Raw provenance is preserved
in [the evidence directory](evidence/full-review-2026-11-27/).

Production capacity decisions require representative workload testing. In
particular, in-memory lifetime retention
and late-page traversal, sparse-due persistent cleanup, application-specific
idempotency, long handler lease sizing and shared-service memory behavior remain
explicit design and operational considerations. The review found no reason to
hide those limits behind new abstractions or stronger claims than the executed
evidence supports.
