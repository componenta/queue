# Verification and implementation plan

This is a derived plan for the behavior in [the specification](specification.md),
[the Cycle profile](cycle-profile.md) and [integrations](integrations.md). It does
not add alternative semantics. [Implementation evidence](implementation.md) records
actual runtime tests and measurements separately from this acceptance plan. A
scenario listed here is not, by itself, evidence that its experiment passed.

## 1. Test philosophy and evidence levels

Use Pest and TDD. For each behavior slice, first write a test against the public
contract and observe its intended failure; implement the smallest coherent change;
then refactor while preserving that behavior. A failing assertion must identify a
missing guarantee, not a missing private helper. Do not generate a complete mock
implementation and call it a transport proof.

Use real values and policies for deterministic logic. Stubs isolate external
inputs such as time or a random integer; spies capture observable handler and
publisher effects. Mocks are appropriate when the communication protocol itself
is the contract, such as not acknowledging before failure-copy acceptance.
Keep assertions outside handlers and callbacks that the worker is allowed to
catch as application failures. Every rejection scenario needs a valid-input
control so a test cannot pass merely because the implementation rejects all work.

| Evidence | Establishes | Does not establish |
| --- | --- | --- |
| Recording publisher | Application selected the recorded message/delay; configured codec accepted encoding | Persistence, scheduling, handler execution |
| Codec/value tests | Bytes, validation, immutable snapshots, deterministic encoding | Broker delivery or SQL concurrency |
| In-memory conformance | Portable behavior and local delivery lifecycle | Persistence across processes or database locking |
| Worker boundary tests | Outcome selection, invocation, settlement errors | Successful real database commits |
| Real PostgreSQL conformance | Atomic transitions, fencing, commit/recovery behavior | External side-effect idempotency |
| CQRS/App/CLI integration | Composition, context trust, build parity, process lifecycle | Universal correctness of every third-party adapter |

Pure backoff and clock-based tests do not sleep. Integration tests involving lease
expiry or process signals may use bounded real deadlines and synchronization
barriers. Avoid timing races disguised as arbitrary sleeps. Do not test private
methods by reflection or assert an incidental SQL string.

## 2. Core acceptance scenarios

The identifiers below provide traceability, not a mandatory test-count target.
Several input variants can share a named Pest dataset; materially different
failure boundaries need their own scenario.

| ID | Scenario and required observable result | Proof |
| --- | --- | --- |
| C01 | Round-trip empty, UTF-8 and arbitrary binary bodies; identity, type/version and headers remain exact | Real codec |
| C02 | Caller mutates referenced input header variables after construction; message and encoded snapshot do not change | Value boundary |
| C03 | Invalid format/version/schema types, duplicate JSON members, invalid base64, unknown fields and configured size/depth excess are rejected | Codec datasets, valid controls |
| C04 | Malformed envelope is received as raw data, never invokes a handler, and failure capture retains the exact raw bytes | Worker + codec + real store integration |
| C05 | No work returns `Idle`; a transport exception escapes and is never mistaken for emptiness | Worker boundary |
| C06 | Handler succeeds; acknowledgment occurs after return and result is `Acknowledged` | Spy, state inspection |
| C07 | Handler succeeds but acknowledgment is no-effect/unknown; no retry/failure disposition is attempted | Fault injection at settlement boundary |
| C08 | Handler fails; retry/failure success precedes its success-looking result; a storage failure leaves recoverable work | Worker plus real transaction test |
| C09 | Handler and settlement/policy both fail; both errors remain observable, without overwriting the original cause | Exception contract |
| C10 | Codec implementation fails unexpectedly; it is not mislabeled as a malformed message and the source is not acknowledged | Worker boundary |
| C11 | Initial delivery has zero scheduled retries; each accepted retry increments once; native redelivery does not increment it | Shared suite + profile tests |
| C12 | Finite retry policy stops at its configured limit; classifier, cap, full-jitter bounds and overflow handling are correct | Pure policy datasets |
| C13 | Unknown alias/schema never constructs an application object; duplicate registrations fail at composition | Registry/handler integration |
| C14 | Handler sees metadata and renewal capability, but no settlement/native connection access | Public signature and behavior review |
| C15 | Unsupported/invalid delay or renewal fails before a side effect; supported delay cannot execute early | Shared suite + adapter time authority |
| C16 | Repeated/opposite settlement, receive with outstanding delivery, and retained lease after settlement fail before I/O | Local lifecycle conformance |
| C17 | Indeterminate/lost delivery invalidates the session; no opposite disposition or handler replay occurs | State/fault scenarios |
| C18 | Closing is idempotent, prevents further receive, never acknowledges active work and does not close a borrowed application connection | Resource boundary |
| C19 | Logger failure does not change disposition or turn handler success into failure | Throwing logger double |
| C20 | Message A's tenant/request state does not appear in B; scope cleanup failure is not acknowledged as success | App/handler decorator integration |
| C21 | Failure lookup distinguishes not-found from storage errors; pages are bounded, body-free and enforce cursor scope | Failure store conformance |
| C22 | Redrive preserves ID/raw content and starts one run; same parameters are idempotent; conflicting redrive/discard cannot cancel later work | Shared store suite + database concurrency |
| C23 | Renewal fails inside the handler, whether propagated or caught; no policy or disposition follows, receiver closes, and original/close failures remain observable | Lease/worker boundary |

Check repeated/indeterminate operations with both negative and successful
controls. Mock call ordering alone is insufficient for durable guarantees.
Represent all local delivery states explicitly in the conformance fixture, but
assert their externally observable operations rather than private state fields.

## 3. PostgreSQL proof

Use PostgreSQL with at least two independent physical write connections and,
where crash boundaries matter, separate PHP processes. The test fixture records
the server version, isolation and durability settings, schema and timeouts.
SQLite, a mocked `PDO`, or two handles sharing one transaction do not substitute
for these tests.

| ID | Required experiment |
| --- | --- |
| D01 | Competing consumers: a committed reservation has one current token; another consumer can acquire a different unlocked ready row |
| D02 | Claim rollback leaves no durable counter increment; handler execution begins only after a known successful claim commit |
| D03 | Process exits after claim and before handler; expired work becomes eligible and exact delivery count includes the lost reservation |
| D04 | After reclaim, old acknowledge/retry/fail/renew attempts cannot affect the newer reservation |
| D05 | Hold a row lock until the original lease expires; the waiting settlement obtains the lock, reads fresh DB time, and rejects the expired token |
| D06 | Lease equality boundary is expired; renewal replaces the remaining duration from DB time and cannot revive an expired lease |
| D07 | Retry commits its availability/counter/token change together; a confirmed rollback leaves the transition unapplied |
| D08 | Failure insertion fails; source work remains recoverable and no completed tombstone is created |
| D09 | A record exhausts delivery budget through crashes; a later bounded recovery pass quarantines it instead of leaving an immortal leased row |
| D10 | Concurrent same-ID/same-content publication yields one retained logical record; different bytes/headers/original delay conflict; ID/queue byte limits and byte-distinct Unicode/case identities obey the profile |
| D11 | Retained completion prevents a late duplicate; explicit terminal cleanup permits a new incarnation without rebinding historical failure IDs |
| D12 | Transactional staging is invisible before business commit and disappears on rollback; it never commits the caller's transaction |
| D13 | Long staging transaction consumes the staging-based delay but cannot expose work before commit |
| D14 | Ordinary publisher/consumer/settlement rejects an unrelated open transaction; a savepoint is not treated as durable commit |
| D15 | Connection loss around commit produces the correct uncertain outcome; reconnection does not justify speculative handler execution |
| D16 | Concurrent redrive of one failure creates one new generation; differing parameters and redrive/discard races have deterministic conflicts |
| D17 | Cleanup races publication/redrive without deleting live work or changing a newer incarnation; unresolved failures survive unrelated retention expiry |
| D18 | All authority-bearing reads use the write connection; replica lag cannot make a stale token valid |
| D19 | Invalid backend/version/configuration is rejected before consuming; no quiet downgrade to a weaker algorithm occurs |

Use barriers to control lock acquisition and release. To test uncertain commit,
use a fault boundary that can forward COMMIT and suppress its response, and
inspect actual durable state independently afterward. A fake exception labeled
"connection lost" proves only worker classification; it does not prove whether a
real driver can identify the commit boundary. Record both the committed and
rolled-back cases without assuming which one a disconnected caller can know.

Recovery scanning and claim behavior must also be exercised with many exhausted
rows before ready work, a large delayed backlog, and a large terminal history.
Verify bounded work per poll and that a later poll can make progress. The suite
must not interpret a `null` poll as a global empty-queue assertion.

## 4. Integration proof

Use the criteria in [integrations](integrations.md) for the actual CQRS 5 and App 6
target sources. In particular:

- Async dispatch publishes; consuming dispatches in trusted synchronous mode and
  does not enqueue recursively. Message ID/original-operation provenance remain
  stable while the new runtime operation has a new identity and timestamp.
- An uncertain publish before `dispatch()` returns retains the original message,
  destination and delay in its bridge exception; recovery does not regenerate ID
  by redispatching the command.
- Unknown aliases and forged runtime context cannot trigger constructor execution
  or overwrite the trusted execution mode. Synchronous queries never use the queue.
- Local CQRS retries and durable queue retries have a documented owner and budget;
  transaction boundaries are checked when the local retry option is used.
- Source, prepared and warm registrations give identical routes, conflicts,
  errors, codec selections and behavior. Preparation starts from fresh source
  definitions and a failed build publishes no partial map.
- CLI help/list do not acquire messages or open unnecessary external connections.
  SIGTERM/SIGINT stop new polling and drain current work; hard process termination
  still recovers through lease expiry. No SQL runs from signal callbacks.
- Legacy/new queues use explicit separate formats and destinations. Drain/import
  verification never converts completed tombstones or failed records into work
  without an explicit disposition.

Tests for authorization and external service idempotency belong to their owning
application boundary. Include an end-to-end example with a transactional local
inbox when demonstrating that guarantee; do not market the queue itself as
exactly-once execution.

## 5. Performance evidence

Use phpbench for deterministic local subjects: message/header validation,
encoding/decoding for small and larger configured payloads, registry dispatch,
backoff calculation, and a worker step through an in-memory adapter. Consume
results so a benchmark does not measure dead code. Separate fixture setup and
message creation from the operation under measurement where appropriate.

Use a separate real-PostgreSQL workload for committed publication, claim,
acknowledgment, retry, failure capture and whole publish-to-handler latency.
Measure one consumer and competing processes, empty/delayed/ready backlogs,
failure-heavy traffic, expired leases and tombstone growth. Report throughput,
latency distributions, CPU/RSS, query/connection counts and contention alongside
PHP/database versions, payload sizes and durability settings. Do not compare a
no-op fake with a committed database write as though they were equivalent work.

The specification establishes no numeric SLO. Establish a repeatable baseline
before optimization. Accept an optimization only when the same behavioral and
concurrency suite passes and a repeated measurement demonstrates its benefit.
Source/prepared startup cost and steady-state handling cost are separate subjects.
Observed measurements are kept in [implementation evidence](implementation.md) and its linked baseline records, rather than inferred from this plan.

## 6. Ordered implementation slices

These slices record the original implementation order. All five packages now
have independent repositories and Composer manifests; the PostgreSQL adapter
lives in [queue-cycle](https://github.com/componenta/queue-cycle). Current executed
checks are recorded in [implementation evidence](implementation.md), and the
integration packages document their exact development dependency baselines.

### Slice 1 — One processable message

**Depends on:** none. **Targets:** I1, I7, I8; C01–C06, C13, C14.

Write a failing round-trip and successful worker-step scenario. Add the core
manifest/autoloading and the values, default codec, narrow ports, concrete worker
and in-memory fixture needed to satisfy it. Do not add empty interfaces or
abstract job hierarchies without a client. Confirm that malformed bytes remain
available after decoding fails. The slice is done when a message can be published,
received, handled and acknowledged through public APIs and invalid messages have
the specified observable failure path.

### Slice 2 — Controlled failure and delivery lifecycle

**Depends on:** slice 1. **Targets:** I2–I8; C07–C12, C15–C23.

First reproduce incorrect handling of acknowledgment uncertainty, counter limits,
retained delivery capabilities, and handler-plus-settlement errors. Implement the
bounded policy/backoff, local states, failure models, recovery API and diagnostics
that make those tests pass. Add the shared conformance suite for core ports and
profile-independent failure-store behavior. Done means every worker outcome has
a defined error path and no automatic disposition can lose the original work.

### Slice 3 — Durable PostgreSQL publication and claim

**Depends on:** settled core ports from slices 1–2. **Targets:** Cycle sections
2–6 and 8; D01–D06, D10, D14, D18–D19.

Create the separate `queue-cycle` package and migrations for the specified state,
constraints and access paths. Use real PostgreSQL tests first, including a stale
worker blocked until after expiry. Implement idempotent publication, claim,
acknowledgment, strict renewal, authoritative DB time and ownership checks.
Run the publication/claim/acknowledgment/renewal subset of the portable suite and
the corresponding parts of D04 against this adapter. The complete managed
delivery contract, full D04 and full portable suite are acceptance gates after
slice 4; do not publish an incomplete adapter as conforming or hide unfinished
retry/fail behavior behind successful no-ops. This slice establishes the durable
reservation seam and its stronger database ownership rules.

### Slice 4 — Durable recovery, failed work and transactional staging

**Depends on:** slice 3. **Targets:** Cycle sections 4–9; D07–D17, C04, C21–C22.

First demonstrate the crash/commit and failed-storage gaps, then implement atomic
retry/quarantine, exhausted-budget recovery, redrive/discard, bounded retention
cleanup and explicit staging in the application transaction. Exercise concurrent
administrative requests and late publications. Done means no tested failure
window silently removes work, retention/replay guarantees match their stated
scope, and the full portable and PostgreSQL suites pass. A local SQL queue does
not require a duplicate outbox subsystem.

### Slice 5 — Ordinary application use and CLI supervision

**Depends on:** slices 1–4. **Targets:** worker lifecycle and integration criteria.

Provide a documented non-CQRS example with a registered handler and an explicit
idempotent local business effect. Build the separate console loop and bounded
failure administration using the core contracts. Verify process signals and
termination independently of business tests. Done means the library is useful
outside Componenta App and CQRS and can run under a process supervisor.

### Slice 6 — CQRS and App integration

**Depends on:** stable core behavior and the dependency/source gates in
[integrations](integrations.md). **Targets:** I8–I9 and all integration criteria.

Implement only the chosen `cqrs-queue` bridge and `queue-app` composition. Verify
trusted redispatch and build parity first. Document and test explicit legacy
drain/conversion. Remove superseded glue from the migrated application when that
application no longer uses its legacy route; do not delete unrelated published
packages or silently leave two implementations claiming the same route.
Done means one deliberate integration path exists, with verified Composer
resolution and no assumed unreleased tag.

### Slice 7 — Release evidence and optimization

**Depends on:** the slices included in the proposed release. Run the relevant
suite on supported PHP/database targets, static analysis, source/prepared parity
where applicable, and the reproducible benchmark workload. Resolve material drift
between code and the normative specification. Publish only packages with passing
evidence and satisfiable declared dependencies. There is no requirement to finish
deferred brokers before releasing a proven PostgreSQL-backed version.

## 7. Acceptance traceability and completion

| Invariant | Principal proof |
| --- | --- |
| I1 — identity/authority | C01, C11, C16–C17; D01–D06, D10–D11 |
| I2 — recoverability | C07–C09; D07–D09, D15–D17 |
| I3 — uncertainty | C07, C09, C17, C23; D02, D15 |
| I4 — handling boundary | C04–C10, C13 |
| I5 — scheduling | C12, C15; D07, D12–D13 |
| I6 — bounded repetition | C11–C12; D03, D09, D16 |
| I7 — raw preservation | C01, C03–C04; D08 |
| I8 — isolation | C02, C14, C16–C20; CQRS context and App scope tests |
| I9 — mode parity | Source/prepared/warm integration matrix |

Review API call sites as well as tests: an ordinary publisher, a custom handler,
a worker runner and a failed-message operator must each complete their task
without native clients, private receipts or fragile external mutation sequences.
Check actual dependency direction from the manifest/import graph. Static checks
can guard that constraint, but they do not replace runtime evidence.

After implementation, record which scenarios executed, the exact environment and
remaining limitations. Classify divergence as missing, partial, contradictory or
unrequested behavior and correct its owning source. A syntax check, test count,
coverage percentage or benchmark number alone is not the completion criterion.
