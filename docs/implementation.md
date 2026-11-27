# Implementation and verification evidence

Snapshot date: **2026-11-27**. This is development source, without a stable release
tag. The behavioral specification remains the contract; this file records what
has been implemented and observed rather than treating every planned scenario as
already proven.

## Delivered scope

| Area | Implementation |
| --- | --- |
| Message boundary | Final immutable values; reference-safe header snapshots; stable alias/schema and caller-supplied ID |
| Wire codec | Fixed versioned JSON; strict content type and canonical base64; duplicate member rejection; explicit byte/depth limits |
| Processing | Concrete Worker; restricted lease; acknowledgment after handler completion; sticky renewal failure; preserved processing/infrastructure/close errors |
| Policies | Exact handler registry; permanent rejection; finite retry budget; overflow-safe exponential interval and full jitter |
| Memory transport | Publication, delay, reservation, fencing, retry, failure capture, pagination, redrive/discard; injected clock; bounded rotating polling |
| Test publisher | Bounded publication records, real codec validation, immutable snapshots |
| Persistent transport | Independent [queue-cycle repository](https://github.com/componenta/queue-cycle) with schema, write-connection transactions, reservation and failure-store implementations |
| Development | Pest, shared portable adapter contract, PHPStan max, phpbench, runnable plain-PHP example and CI configuration |

The core requires only PHP/JSON, PSR Clock and PSR Log. Cycle is no longer a core
development dependency: the adapter owns its own dependency installation, real
PostgreSQL suite and CI matrix. Independent Console, App and CQRS repositories
are listed in [the integration document](integrations.md).

## Repository separation

The reviewed core PR was merged as `a90692fe8d62ffba9baa2002555e8ffdea413cf3`.
The adapter source, SQL, tests, workload and historical measurements were moved
to `componenta/queue-cycle`. Its development Composer root installs core as the
explicit `1.0.x-dev` line; production constraints remain `^1.0`.

After separation, core again passed **245 tests / 572 assertions**, maximum-level
PHPStan, PSR-12 checks, strict Composer validation and the runnable example.
The independently installed adapter passed **56 tests / 262 assertions** on
PostgreSQL 17.12 (15.90 seconds), PHPStan max and its own formatting checks.
Its bootstrap loads the shared portable contract from the installed core, with
no fallback to a monorepo vendor directory. The historical full matrix and
performance evidence below refer to the original implementation commit.

## Independent integration packages

All five queue packages now have independent repositories and runtime manifests.
The core and PostgreSQL source separation is described above. Console, App and
CQRS implementations preserve the original core ports; none introduces a second
queue worker or transport state machine.

| Suite | Tests | Assertions | Main coverage |
| --- | --- | --- | --- |
| Core / in-memory / portable memory contract | 247 | 590 | Wire validation, retry decisions, ownership and atomic failure maintenance |
| queue-cycle | 62 | 287 | Real PostgreSQL transactions, locks, process death, commit uncertainty, DateStyle, exact failure identity and capability ownership |
| queue-console | 51 | 221 | Lazy commands, limits, failure administration, actual process signals, application events and strict output identity |
| queue-app | 29 | 71 | Real App build/runtime maps, DI scope isolation, cleanup, reference safety and declared runtime aliases |
| cqrs-queue | 42 | 168 | Real CQRS dispatch, admission, original identity, uncertain publication recovery, preparation failures and synchronous completion |
| Ecosystem consumer | 4 | 68 | All five packages together on real PostgreSQL, through source and prepared App composition |
| **Distinct scenarios across suites** | **435** | **1,405** | Repeated platform-matrix runs are not added to this total |

The independent review produced additional concrete regressions and fixes:

- DI aliases/delegators could replace message runtime services; those bindings
  are now rejected before constructing the scope.
- A referenced dependency section could receive message-local services and retain
  a completed scope; section slots are detached before runtime injection.
- A structurally valid prepared map could omit or substitute explicit handler
  bindings; its semantics are checked against current explicit configuration.
- A CQRS pipeline returning no OperationResult could otherwise cause premature
  acknowledgment; consumption now requires synchronous completion. A completed
  OperationResult containing a null business value remains valid.
- PHP numeric array-key conversion could reject a permitted destination label;
  publisher maps now normalize numeric destination keys consistently.
- A nested development vendor directory gave Pest the wrong project root. The
  CQRS fixture uses Composer's public vendor-dir setting and verifies the actual
  documented CI command.

### Follow-up audit

A further independent review reproduced five additional defects before applying
the corresponding fixes:

- A PostgreSQL session using `DateStyle = 'SQL, DMY'` broke timestamp reads.
  Adapter-owned transactions now select ISO/YMD locally in their existing
  settings statement. Commit and rollback restore the original setting, and
  transactional staging preserves the caller's settings.
- PDO's text parameter handling could truncate a failure ID at NUL and target
  an existing prefix. Such an impossible PostgreSQL text ID now follows the
  not-found contract; the original record remains unchanged.
- A destructor invoked when releasing a cleanup callback's captured object
  could skip remaining callbacks and replace the handler error. Explicit,
  guarded callback release preserves both errors and completes cleanup.
- A signal received before Symfony input validation failed could persist into
  the next invocation of the same command. Stop state is now finalized around
  the complete command invocation, including pre-execution failures.
- Lossy UTF-8 replacement in Console JSON could change an opaque failure ID or
  continuation cursor. Invalid output now fails before a successful record is
  printed; error diagnostics remain valid JSON even for a byte-oriented PHP
  exception class name.

All affected full suites passed locally, as did maximum-level PHPStan and PSR-12
checks. The all-package PostgreSQL consumer again passed all four scenarios and
68 assertions with App's updated unified-build commit `d2c1a5a`. Earlier CI
confirmations below remain tied to their original commits and test counts.

### Capability and publication-boundary audit

A subsequent review reproduced three further defects through public APIs before
applying the corresponding fixes:

- Cloning a Cycle delivery copied its mutable state independently of its session.
  The copy could acknowledge a reservation after the original receiver closed.
  Cycle receivers and deliveries now reject cloning; both regression cases
  retain successful acknowledgment through the original delivery as a control.
- CQRS prepared the returned asynchronous operation after publishing. A custom
  `OperationInterface` could throw during that derivation after the queue had
  accepted the message. Derivation now precedes publication; its failure leaves
  zero publications and preserves the original error. Existing recovery tests
  still retain the exact message, delay and destination for publisher failures.
- An already declared PHP class/interface alias could evade App's reserved
  runtime-service guard. Declared names are now canonicalized without invoking
  autoloading. Tests cover all three protected runtime services, an ordinary
  application alias and an unresolved service ID that must not be autoloaded.

All five full package suites passed, together with the four PostgreSQL ecosystem
scenarios, for the totals in the table above. Maximum-level PHPStan passed for
all packages; formatting checks passed for the changed PHP packages. No further
confirmed defects were found in core or Console during this pass. The source and
prepared ecosystem paths use the same reviewed App commit `d2c1a5a`.

### Poll failure and Console event audit

The next pass reproduced two additional defects through public dependencies:

- In-memory receiving could capture an exhausted message, then reject the poll
  when a later clock read or lease calculation failed. This contradicted the
  no-effect outcome of `NotApplied` and unsupported-duration errors. Polling now
  prepares failure records and the optional reservation before applying either;
  rejected polls return all visited candidates without changing their state.
  Two regressions cover clock failure and timestamp overflow, followed by
  successful recovery through the same receiver. The 64-candidate maintenance
  bound and multi-message progress remain unchanged.
- Symfony can disable a command before `Command::run()` or dispatch an error
  event after its `finally`, while signal handlers remain registered. A signal
  at either point could stop the next invocation. A fresh signal subscription
  now starts a fresh stop request outside an active run. Two regressions and
  two positive controls verify early current-run signals and subscription
  inspection during active handling through the actual Symfony Application.

The resulting core suite passed **247 tests / 590 assertions**, and Console
passed **51 tests / 221 assertions**. Maximum-level PHPStan and PSR-12 checks
passed for both fixes. App and CQRS full suites and all four PostgreSQL ecosystem
scenarios passed against the changed core. The independent Cycle review passed
its full real-PostgreSQL suite and exact maximum-duration probes under differing
TimeZone, IntervalStyle and DateStyle settings, with no further source changes.

### Earlier hosted runs

[Console CI run 46012663041](https://github.com/componenta/queue-console/actions/runs/46012663041)
passed on Symfony Console **7.4.24 and 8.1.12**, with 43 tests / 191 assertions,
maximum-level PHPStan and PSR-12 checks in each job.
[Standalone Cycle CI run 46010762517](https://github.com/componenta/queue-cycle/actions/runs/46010762517)
passed on PostgreSQL **16.16, 17.12 and 18.7**, each with 56 tests / 262 assertions.
[Core separation CI run 46010663687](https://github.com/componenta/queue/actions/runs/46010663687)
also passed. The package documents record the complete reproduction commands and
reviewed dependency references.

## TDD and review evidence

Tests were executed in the failing state before adding each behavior slice:
values/codec, retry/handler, publication spy, Worker, memory transport and the
persistent adapter. They assert public behavior and observable effects, without
reflection into private state. Worker assertions are outside callbacks that may
be caught as ordinary application failures.

Independent review produced additional concrete regression checks:

- A `DecodingException` raised while obtaining raw bytes must remain an adapter
  infrastructure failure. Only the codec's own rejection causes decode quarantine.
- A failed lease renewal stays failed even if the handler catches it and tries
  again. The Worker closes its receiver without selecting another disposition.
- `rollbackTransaction() === false` is not confirmation of rollback. The adapter
  reports an indeterminate outcome instead of claiming no effect.
- Expired resolved history must not block later cleanup merely because a redriven
  generation is active. Cleanup preserves the newer work and makes bounded progress.

## Original implementation checks

The local execution environment uses PHP **8.5.13**, Pest **4.7.8**, PHPUnit
**12.5.33**, PHPStan **2.3.7**, phpbench **1.7.1** and Cycle Database **2.24.0**.
Composer's installed versions are pinned in the root lockfile for repeatable
development. Consumers resolve the library's declared runtime constraints.

The final local checks were run independently after PSR-12 formatting:

| Check | Observed result |
| --- | --- |
| Core, values, policies, Worker, memory transport and shared memory contract | **245 passed, 572 assertions** |
| Complete real PostgreSQL suite, including the same portable contract | **56 passed, 262 assertions**, PostgreSQL **17.12**, 15.33 s |
| Combined behavioral tests | **301 passed, 834 assertions** |
| PHPStan `level: max`, `src` and `packages/cycle/src` | **0 errors**, no baseline or suppression |
| PHP CS Fixer 3.95.32, `@PSR12` | **0 pending changes** across 123 PHP files |
| Root and Cycle Composer manifests | Both `composer validate --strict` checks passed |
| `php examples/basic.php` | Handler ran; final result `acknowledged` |
| phpbench | Six subjects, zero failures/errors; observed values in the linked baseline |

### Hosted CI confirmation

[GitHub Actions run 46007356923](https://github.com/componenta/queue/actions/runs/46007356923)
completed successfully for implementation commit
[`856b2b3`](https://github.com/componenta/queue/commit/856b2b3ee775980a9864e36f6d021e7f88be5a95).
Job logs, rather than the configured matrix alone, confirmed these results:

| Hosted job | Actual server version from its logs | Observed result |
| --- | --- | --- |
| Core | No database | 245 tests / 572 assertions; PHPStan max, PSR-12, Composer validation and example passed |
| PostgreSQL 16 | **16.16** | 56 tests / 262 assertions passed |
| PostgreSQL 17 | **17.12** | 56 tests / 262 assertions passed |
| PostgreSQL 18 | **18.7** | 56 tests / 262 assertions passed |

The jobs used PHP 8.5.13. This documentation update records that completed run;
it does not change the measured implementation source or dependency lockfile.

The PostgreSQL fixture used isolated schemas and independent physical write
connections. Process/lock tests used separate PHP processes and explicit
synchronization. The test-owned process was actually terminated with SIGKILL
in the crash-recovery experiment. Test servers were stopped after the runs.

### Persistent acceptance coverage

| Planned scenarios | Executed evidence |
| --- | --- |
| D01–D05: ownership, rollback, crash, fencing and post-lock time | `OwnershipTest.php`, `TransactionsTest.php`, `CommitOutcomeTest.php` |
| D06: renewal/expiry | Real shorter renewal, expired renewal rejection and post-lock expiry; exact same-microsecond equality was not forced on the live server |
| D07–D09: atomic retry/failure and exhausted delivery recovery | Real constraint failures on both sides of multi-table transitions; bounded maintenance followed by ready-work progress |
| D10–D11: identity and retained completion | Concurrent publication arbitration, byte/case/Unicode/header controls, retained-history cleanup and new-incarnation checks |
| D12–D14: business transactions | Staging invisibility, rollback, unchanged caller settings, delay anchored before a long commit, rejection of ordinary operations inside transactions/savepoints |
| D15: uncertain commit | Actual TCP relay drops COMMIT before forwarding or suppresses the server response after commit; a separate connection inspects durable results |
| D16–D17: administration and cleanup races | Separate blocked processes exercise redrive, conflicting delay/discard, cleanup/publication and cleanup/redrive |
| D18: write authority | Actual separate READ driver points to an empty schema; authority-bearing operations still use WRITE |
| D19: platform gates | UTF8, readonly, identity/duration boundaries exercised; version/block-size guards reviewed, unsupported server/build not executed |

The uncertain-commit experiments include a committed reservation whose response
was lost: no handler ran. A committed acknowledgment with a lost response did not
cause retry or an opposite disposition. A post-commit snapshot-construction
failure also closes the direct receiver while preserving recoverable leased work.

Cursor tests reject a cursor after table reinstallation while a fresh connection
to the same installation continues correctly. An additional isolated review
experiment reproduced and fixed cross-database cursor acceptance under the same
configured database alias/schema/queue; all three review controls passed afterward.

The source comparison uses `lease_until <= dbNow` for expiry. Exact equality is
covered by the frozen-clock memory adapter; a live PostgreSQL same-microsecond
boundary is not claimed as an executed experiment. Local server coverage is
17.12; hosted CI additionally passed on 16.16 and 18.7. Unsupported PostgreSQL
15/small-block builds were not executed. The workload includes 10,000 delayed records; a large terminal-history
benchmark and comparative multi-consumer throughput were not measured.

## Measurements

The core phpbench suite measures real value/codec operations, exact handler
routing, capped backoff calculation and a complete memory publication/processing
round trip. It consumes each result; the round-trip subject checks that every
operation actually acknowledged a new message.

The first run identified a per-character PHP loop in the validated JSON string
scanner. Replacing ordinary string-run traversal with `strcspn()` preserves escape
and duplicate-key semantics while reducing measured decoding cost. All relevant
public codec and adapter tests pass after the change. The observed comparison and
its variance belong in [the core baseline](evidence/core-baseline.md).

The persistent workload has separate results in
[the PostgreSQL baseline](evidence/postgres-baseline.md). Local in-memory timings
are not compared with committed database writes as equivalent work. These are
exploratory measurements on a shared host, not production SLOs.

## Release and integration boundaries

The supported-target CI jobs passed as recorded above. Stable release still
requires satisfiable published package dependencies and review of deployment
and retention choices; this development change creates no release tag.

`componenta/queue-cycle` targets `componenta/queue:^1.0`; publish or otherwise
provide the real compatible core version before releasing that package. Development branch aliases retain the `-dev` suffix; none represents a stable
release. App 6 and CQRS 5 integration dependency baselines are recorded in
[integrations](integrations.md).

The core provides `processOne()`, not a production process supervisor. Signal
handling, shutdown-loop tests and bounded administration commands belong to the
independent [Console package](https://github.com/componenta/queue-console). Application inbox/authorization and external-side-effect
idempotency belong to the application and are not established by these tests.
