# Componenta integrations

This document specifies integration boundaries for the proposed queue library. The [core specification](specification.md) owns public API names and delivery semantics; the [Cycle profile](cycle-profile.md) owns database guarantees. The core and Cycle adapter have implementation source; this document continues to describe the separate, unimplemented optional integration packages. See [implementation evidence](implementation.md) for delivered scope.

## Package boundaries

| Package | Responsibility | Dependencies it must not acquire |
|---|---|---|
| `componenta/queue` | Immutable messages, transport-owned deliveries, worker orchestration, delayed retry and failure capture contracts | CQRS, App, DI, Config, ORM, a service container |
| `componenta/queue-cycle` | Durable storage, claiming, settlement, database scheduling and transactional local staging | CQRS, Componenta ORM integration |
| `componenta/cqrs-queue` | The single new CQRS bridge: command publication, command decoding and synchronous dispatch during consumption | An independent copy of the queue worker or broker adapters |
| `componenta/queue-console` | Console options, process limits and signal integration | CQRS-specific command decoding |
| `componenta/queue-app` | Configuration, dependency registration, optional discovery and preparation | Ownership of delivery state or message execution |

The bridge is a new package. This project does not simultaneously redesign `cqrs-transport` into another queue abstraction. Existing transport packages remain available for migration and draining.

Core `Message` contains immutable bytes and headers with a stable caller-supplied identifier. It contains no receipt, connection, handler, container or PHP object graph. `Delivery` is owned by the transport. Application handlers receive `ProcessingContext` without settlement authority; the worker owns the decision and ordering of settlement. Integrations must preserve these boundaries.

No HTTP middleware contract is reused for queue processing. Similar pipeline syntax does not imply compatible failure, return-value or lifecycle semantics.

## Dependency baseline and release constraints

PHP `^8.5.13` is the project baseline, aligned with the inspected CQRS development baseline. This is a project choice, not a queue protocol requirement.

| Dependency | Intended location and constraint | Readiness condition |
|---|---|---|
| `psr/clock:^1.0` | Clock dependency where application timestamps are created | Inject the clock; do not obtain it from a container inside core services |
| `psr/log:^3.0` | Optional operational logging through the core's configured logger | Logging failures must not change delivery disposition |
| `cycle/database:^2.24` | `queue-cycle`, directly | Implemented against 2.24.0; validate the supported database matrix before release |
| `componenta/clock:^1.2` | Optional application composition | `v1.2.0` exists and supports Config 3/4 |
| `componenta/identity` | Application-side ID generation | Core accepts an ID and does not duplicate UUID generation |
| `componenta/cqrs:^5.0` | New CQRS bridge | Target release; latest observed published CQRS tag is `v4.0.0` |
| `componenta/app:^6.0` | App integration | Target release; latest observed published App tag is `v5.0.0` |
| Config 4 / DI 6 | App integration when their APIs are used | Verify one installable dependency graph before releasing that integration |

These constraints do not block independent queue core development or release. An integration must not publish a stable package whose required compatible versions are unavailable. A development integration fixture may pin reviewed branches explicitly; production manifests must not hide this with aliases pretending an unreleased version is released.

Identity `v1.1.0` currently requires Config 3. Config 4 compatibility exists on the inspected compatibility branch, without a corresponding newer tag. Consequently, adding Identity directly to queue core would currently introduce an unrelated configuration dependency and a compatibility obstacle. Applications can use `UuidFactoryInterface` and pass its string result. A shared injected PSR-20 clock supplies application timestamps; storage coordination uses the adapter's authoritative clock.

The existing `cqrs-transport` manifest requires CQRS 4 and Config 3. It is not evidence that the new CQRS 5/Config 4 integration is installable. Likewise, `componenta/cycle` is an ORM integration with additional framework dependencies; queue SQL operations require `cycle/database`, not that entire package. These observations are pinned below, rather than inferred from repository names.

## CQRS publication and consumption

### Publication

The bridge registers an explicit mapping between an allowed command type and a stable message alias plus schema version. Aliases are application protocol names, not independently executable FQCNs supplied by a message producer.

For an asynchronous command dispatch, the bridge must:

1. Resolve the trusted route and serializer registration, validate supported scheduling options, and serialize the command before publishing.
2. Create one message with the original operation identifier as its stable message ID, subject to the core identifier rules. Preserve that ID across queue retries.
3. Serialize only explicitly permitted operation context. Exclude results, containers, handlers, credentials and runtime control fields.
4. Publish through the queue producer and preserve the core distinction between definite failure and an unknown publication outcome.
5. Return the deferred operation without executing the command handler. Transport acceptance does not mean command completion.

The CQRS bus creates its operation inside `dispatch()`. If publication throws before that method returns, the caller may otherwise lose the original publication identity. The bridge's publication exception therefore exposes the immutable original `Message`, original requested delay, configured destination label and classified transport outcome/cause. Recovery retries that exact publication through its destination publisher. Calling `CommandBus::dispatch()` again creates a new operation and is not publication recovery. Never retry that dispatch automatically or print the retained body in default exception diagnostics.

The existing context serializer excludes names beginning with `__`; the new bridge must retain an equally strict boundary. A transport-provided value must never override worker execution mode or trusted application context. Deserializing an authenticated producer's message does not by itself authorize its tenant or business action.

### Consumption

Admission precedes object construction: check the alias, schema version, configured queue route and codec registration before resolving or instantiating the command. Validate the complete transported context and reject reserved runtime fields before invoking the registered command decoder or constructor. Unknown aliases must not trigger arbitrary `class_exists()`, autoloading or reflection-based construction. A registered codec may construct its known command type after validating its payload. Its result must match that registered type before dispatch.

The bridge then calls the CQRS command bus with a trusted synchronous execution marker. This prevents consumption from publishing the command again. Transported context is allowlisted first; trusted worker attributes are applied afterward and cannot be replaced by input headers.

The current worker establishes the relevant behavior: it sets `__original_operation_id` and `__execution_mode = SYNC` before redispatch [S1]. Preserve those meanings inside the bridge; the core must not know these CQRS keys.

Redispatch creates a new runtime `Operation` with a new execution identifier and creation time. The original operation ID remains explicit provenance, while the queue message ID remains stable. The queue bridge does not add `OperationFactory::restore()`, reconstruct a completed operation, or serialize its result. The existing factory already delegates UUID generation and time to injected contracts [S2].

Queries remain synchronous. `QueryBusInterface::handle()` returns a result; enqueueing a message cannot fulfill that contract [S2]. Request/reply correlation, result storage, deadlines and cancellation would require a separate future RPC design.

### Retry and failure ownership

Queue retry policy owns delayed, durable redelivery. Existing CQRS retry middleware performs repeated handler calls with sleeps inside one process [S3]. The new bridge must not enable both mechanisms implicitly. If an application explicitly combines them, document their total invocation budget and transaction placement; five deliveries with three local attempts can invoke a handler fifteen times.

Preserve the existing separation between processing and settlement failures: an acknowledgement failure after successful processing must not enter the handler-failure path. A processing failure followed by failed settlement must retain both causes. Logging failures must not alter either decision [S1]. Poison-message decoding failures enter the core failure path without executing a command.

## Database integration invariants

The existing Cycle transport provides useful invariants that the new adapter must preserve or explicitly strengthen [S4]:

| Existing invariant | Requirement for the new profile |
|---|---|
| Queue reads use the write driver | Claiming and settlement cannot depend on replica freshness |
| Coordination time comes from the database | PHP worker clock skew must not decide lease ownership |
| Each claim receives a fresh token | A stale delivery cannot settle a later owner's delivery |
| Identity and payload comparisons are byte-exact | Collations must not merge different IDs or wire content |
| Repeated ID with different content is rejected | Idempotent publication cannot silently substitute an existing message |
| Completed/failed rows retain deduplication tombstones | Specify retention and the resulting deduplication horizon |
| Terminal failure transition and failure record are transactional | A failed capture must not remove the original work |
| Claim contention has bounded polling work | A poll returning no delivery is not proof of global emptiness |

Do not copy schema or locking details blindly. The Cycle profile defines supported engines, token checks, retry transitions and cleanup. Test these promises with real database connections, not only an in-memory adapter.

The first database integration uses `TransactionalPublisher::stage()` on the same physical connection and transaction as the business update. It does not require an additional outbox table. Two connections aimed at the same database do not establish atomicity. A future external-broker integration may use an outbox on that business connection; its relay must preserve stable message identity and expose publication uncertainty. Neither local staging, an outbox nor deduplicated enqueue guarantees exactly-once business effects.

## Console and application preparation

The console integration owns signal registration and process limits. After a stop request it must stop new polling and follow the configured grace policy for current work. The core closes the receiver on infrastructure failure; the console stops the loop and also closes in `finally` on normal exit. Close is idempotent, and restart uses a new session. It must not claim that ordinary synchronous PHP can interrupt arbitrary blocking handlers safely. Runtime limits use monotonic elapsed time; business timestamps and database leases have different clock owners.

Constructors, console command discovery, `list`, and `--help` must not consume messages or establish network connections merely to describe commands. Retry waits belong to durable queue scheduling; idle polling and infrastructure backoff must remain interruptible according to the worker contract.

`queue-app` may prepare routing and codec registrations using App's existing build infrastructure [S5]. Preparation is optional: explicit registrations and source discovery must produce the same observable runtime behavior as prepared and warm modes.

The integration must obey these rules:

1. Use `ApplicationBuilderInterface` in the integration package only. Read fresh `DISCOVERY_SOURCE`, not a previously selected runtime map.
2. Preserve registration order, duplicate detection, alias/version validation, routing and policy errors across all modes.
3. Store stable registration descriptions, never live services, closures, secrets, message payloads, connections or receipts.
4. Version the package payload and bind it to the App build generation. Publish complete artifacts atomically using the shared map infrastructure.
5. Follow App's source fallback policy for absent, malformed or incompatible artifacts. Honor `APP_DISCOVERY_SOURCE=1`; runtime fallback does not write artifacts.
6. Remove only the integration's own artifacts during `app:clean`. Follow App's separate-process clean/build procedure when newly discovered configuration providers must enter composition.
7. Treat PHP maps as trusted deployment artifacts. Build does not run migrations, provision broker topology, publish messages or start consumers.
8. Publish artifacts before starting or reloading workers. A running worker retains its coherent registration snapshot.

## Migration

Use different queue names and a versioned wire format for the new system. Do not auto-detect old CQRS envelopes as new messages. Stop legacy producers or switch their route at a controlled deployment boundary, and drain the old queue with its existing worker. Enable each producer's new route once; do not dual-publish implicitly.

If draining is impossible, use a separately tested, explicit offline converter while old consumers are stopped. Preserve original IDs and provenance, classify pending versus failed versus completed rows, and never convert completed tombstones into executable work. Check duplicates against the new adapter's documented identity scope before importing. Failed records require an explicit replay decision; format conversion alone is not authorization to rerun them.

Keep rollback possible while legacy pending work remains. Do not remove old queues, failed records or tombstones as an automatic consequence of installing the bridge.

## Integration acceptance criteria

Implement these as Pest behavior tests, first observing the intended failure, then implementing the behavior. Use spies for invocation evidence outside worker catch blocks; an assertion thrown inside a handler may itself be treated as processing failure. The complete strategy is in [verification](verification.md).

| Scenario | Required observable result |
|---|---|
| Async command dispatch | Exactly one message is accepted; handler is not invoked |
| Publication outcome unknown before dispatch returns | Exception retains the original message, delay and destination for exact publication recovery; no automatic redispatch |
| Consumption through the CQRS bridge | Handler executes synchronously; no recursive enqueue occurs |
| Unknown alias, unsupported schema or forged runtime field | No command construction or handler invocation; defined failure outcome |
| Redelivery | Message ID and original-operation provenance persist; runtime operation is new |
| Query invocation | Query result follows the synchronous query contract; no queue publication |
| Handler success followed by failed/unknown acknowledgement | No handler-failure rejection is attempted |
| Processing failure and settlement failure | Both causes remain available; success is not reported |
| Stale SQL delivery after another claim | Stale settlement cannot affect the new delivery |
| Failure capture rollback | Original work remains recoverable |
| Local staging with business rollback | Neither business change nor staged queue message becomes committed |
| Stop signal with active work | No new poll; current work follows the documented grace policy |
| Source, prepared and warm application modes | Same routes, codec choices, errors and handler outcomes |

Add valid-message controls alongside rejection tests. Exercise real concurrent database consumers and separate-process CLI behavior where those boundaries determine correctness. Do not turn implementation details such as a particular SQL statement or private method call order into the public contract.

## Pinned evidence

Observed on 2026-11-27; future release constraints above remain proposals until verified against published tags.

- **S1 — Existing CQRS transport:** [contract](https://github.com/componenta/cqrs-transport/blob/2c04e7dd0ffbcdbf8aa6fa82181b9dd96d65e50d/src/Command/Transport/TransportInterface.php), [envelope](https://github.com/componenta/cqrs-transport/blob/2c04e7dd0ffbcdbf8aa6fa82181b9dd96d65e50d/src/Command/Transport/Envelope.php), [worker](https://github.com/componenta/cqrs-transport/blob/2c04e7dd0ffbcdbf8aa6fa82181b9dd96d65e50d/src/Command/Transport/CommandWorker.php), [manifest](https://github.com/componenta/cqrs-transport/blob/2c04e7dd0ffbcdbf8aa6fa82181b9dd96d65e50d/composer.json).
- **S2 — CQRS 5 compatibility baseline:** [manifest](https://github.com/componenta/cqrs/blob/9d3da0fcb8a0d59d9639d2c5da2adb942dc7953f/composer.json), [operation factory](https://github.com/componenta/cqrs/blob/9d3da0fcb8a0d59d9639d2c5da2adb942dc7953f/src/Command/OperationFactory.php), [query contract](https://github.com/componenta/cqrs/blob/9d3da0fcb8a0d59d9639d2c5da2adb942dc7953f/src/Query/QueryBusInterface.php).
- **S3 — CQRS retry:** [retry middleware](https://github.com/componenta/cqrs-retry/blob/f9896582ee73e3348d7cc92b9c8b681ca245e280/src/Command/Middleware/RetryMiddleware.php).
- **S4 — Cycle transport:** [database implementation](https://github.com/componenta/cqrs-transport-cycle/blob/8c9d1c8b69ea8e0fab7c9f0b6d2ddcb30f58b0f2/src/Command/Transport/DatabaseTransport.php), [manifest](https://github.com/componenta/cqrs-transport-cycle/blob/8c9d1c8b69ea8e0fab7c9f0b6d2ddcb30f58b0f2/composer.json).
- **S5 — App preparation:** [runtime/build documentation](https://github.com/componenta/app/blob/d1b6af3ac4e61822cd65e4b1ebe01fc9005b53bc/README.md).
- **S6 — Optional identity and clock composition:** [Identity published baseline](https://github.com/componenta/identity/blob/e036d5d787379b69cf85d8c3ae83db732590b676/composer.json), [Identity Config 4 branch](https://github.com/componenta/identity/blob/3feb94e0d64956ecdf7b22abed4a8bd9f597abeb/composer.json), [Clock 1.2.0](https://github.com/componenta/clock/blob/522a039f56e8312160e1e62ed1ae58c6c558bbaf/composer.json).
