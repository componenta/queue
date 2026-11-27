# Componenta Queue

Design for an independent PHP message queue: immutable messages, explicit delivery
ownership, bounded retries, durable failure capture and application integration.

[Русский обзор](README.ru.md)

## Design documents

| Document | Content |
| --- | --- |
| [Behavioral specification](docs/specification.md) | Public contracts, message/wire format, worker behavior, errors, ownership and guarantees |
| [Cycle / PostgreSQL profile](docs/cycle-profile.md) | Atomic reservations and settlement, strict leases, deduplication, recovery, retention and transactional staging |
| [Componenta integrations](docs/integrations.md) | CQRS bridge, dependency baseline, App preparation, Console lifecycle and migration |
| [Verification and implementation plan](docs/verification.md) | Pest/TDD scenarios, real concurrency proof, phpbench methodology and ordered implementation slices |
| [Research and design rationale](docs/research.md) | Symfony, Laravel, Spiral, RoadRunner, Enqueue, standards and primary sources |

The specification is ready to guide implementation of the core and its defined
PostgreSQL profile. This repository currently contains the design and implementation
plan; it does not contain a production queue runtime. PHP/database tests and
benchmarks remain implementation acceptance gates.

## Architecture

- `componenta/queue` owns transport-neutral values, ports, a one-step worker and
  retry/failure behavior. It has no CQRS, App, DI, Config or ORM dependency.
- `componenta/queue-cycle` is the first durable adapter, directly using
  `cycle/database`, with PostgreSQL 16+ as its initial conformance target.
- `componenta/cqrs-queue` adapts asynchronous commands to the queue. Runtime
  operations remain CQRS-owned; queries remain synchronous.
- `componenta/queue-console` owns the process loop and signals;
  `componenta/queue-app` owns framework registration and optional build artifacts.

Package names other than this repository identify planned boundaries. The design
does not claim those implementations or repositories already exist.

## Key guarantees

Message identity and delivery authority are different. Handler success precedes
acknowledgment; failed or uncertain settlement never automatically causes the
opposite disposition. Raw bytes remain available when decoding fails. Scheduling
does not block a worker. The database profile uses current database time, strict
lease checks and atomic transitions. Application side effects must tolerate
redelivery; the library does not promise exactly-once business execution.

PHP `^8.5.13` is the proposed Componenta baseline. Tests use Pest with meaningful
behavioral and real PostgreSQL concurrency scenarios. Performance measurements use
phpbench and separate committed database workloads. See the integration document
for the release constraints of CQRS 5 and App 6.

## License

[MIT](LICENSE).
