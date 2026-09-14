# Forwext Domain / Repository / Service Layer Contract

Status: **Normative implementation baseline**  
Roadmap step: **03.02 — Domain entity/repository/service katmanı**

## Purpose

Forwext separates business state/rules from persistence and HTTP/UI delivery. This boundary is intentionally established before concrete forum aggregates are implemented so later User, Forum, Thread, Post, Ticket, Marketplace and moderation domains do not grow into controller/database coupled code.

## Entity boundary

`Entity` is an identity-bearing domain object. `EntityId` is the canonical opaque core identity value object and supports string/numeric source identifiers without teaching domain code about database auto-increment details.

Entity identity is not authorization. Possessing or guessing an entity id never grants read/write access; application/use-case services must enforce the shared permission model before exposing protected state or invoking mutations.

Entities own state transitions and invariants that naturally belong to that entity. Controllers, repositories and DTOs must not become alternate homes for the same business rule.

## DTO boundary

`DataTransferObject` marks immutable boundary data used to carry normalized data between transport/application layers.

DTOs:

- may contain scalar/value-object data;
- do not perform persistence;
- do not perform authorization;
- do not contain side-effecting domain behavior;
- are not interchangeable with persisted entities merely because fields look similar.

Input validation may create a DTO only after transport/schema checks are satisfied. Domain invariants remain enforced by the domain model.

## Repository boundary

`Repository<TEntity>` is the persistence abstraction for one entity/aggregate type. It exposes `find`, `require`, `save` and `delete` while hiding SQL/table layout from domain/application code.

`AbstractRepository` centralizes typed not-found behavior and rejects persistence of the wrong entity class. Concrete repositories may use the PDO/query layer from 03.01, but raw SQL does not leak through the repository API.

Repositories do not make authorization decisions. A repository may load protected data because an authorized application service needs it; authorization is checked before the use case exposes/mutates that data.

A repository transaction boundary must be selected by the application service when a use case spans multiple writes or locking operations. Hidden per-method transactions are avoided when they would make a multi-step domain operation non-atomic.

## Application service boundary

`ApplicationService` marks use-case orchestration. Application services coordinate:

1. authentication/actor context where relevant;
2. permission checks;
3. input validation/DTO creation;
4. transaction boundaries;
5. repository loading/persistence;
6. domain services/entity operations;
7. domain-event publication;
8. audit/notification/job side effects through shared services.

Application services must not duplicate entity/domain-service invariants. They decide *when/who/within which transaction* a business action occurs.

## Domain service boundary

`DomainService` marks business logic that spans multiple domain objects or does not naturally belong to one entity/value object.

A domain service should remain infrastructure-agnostic where practical. If external information is required, depend on a narrow domain-facing port/interface rather than HTTP/PDO globals.

## Validation model

`Validator<T>` returns an immutable `ValidationResult`. Violations are structured by `path`, stable machine-readable `code`, human-readable message and safe message parameters.

`CompositeValidator` combines independent validators without hiding later errors. `ValidationResult::throwIfInvalid()` converts structured validation failure into `ValidationException` while retaining all violations.

Validation levels remain distinct:

- transport/schema validation: types, shape, byte limits, content type;
- application validation: use-case preconditions and permission-aware input rules;
- domain validation/invariants: state transitions and business truths that must hold regardless of caller;
- database constraints: final integrity backstop, not the only validation layer.

Sensitive values must not be copied into violation messages/parameters merely for debugging.

## Domain event model

A `DomainEvent` is an immutable fact that already occurred in the domain. It carries a stable event name, UTC occurrence time and optional aggregate id.

Entities/aggregates can use `RecordsDomainEvents` to accumulate events during state transitions. `releaseDomainEvents()` is destructive: once an application service takes the events for publication, the aggregate queue is cleared to prevent duplicate publication in the same unit of work.

`DomainEventDispatcher` is the synchronous in-process baseline. Durable/outbox/queue/retry delivery is not falsely implied here; queue/infrastructure work arrives in later roadmap steps. Side effects that must survive a process crash require a future durable mechanism rather than assuming this in-memory dispatcher is durable.

Events are facts, not permissions. A listener still needs appropriately scoped services and must not bypass authorization/audit rules for user-triggered protected actions.

## Error and security rules

- Not-found errors are typed and do not include database details.
- Repository APIs do not expose raw WHERE/SQL escape hatches.
- Wrong entity types fail closed.
- Validation errors retain stable codes but should not expose secrets.
- Domain event names are validated identifiers.
- No domain primitive performs global/session/database access implicitly.
- Entity ids and DTOs never substitute for backend permission checks.

## Acceptance status

03.02 is complete when the reusable entity/DTO/repository/application-service/domain-service boundaries, structured validation model and domain-event recording/dispatch model exist as real PHP code with tests for identity, repository type safety/not-found handling, validation aggregation and domain-event release/dispatch behavior.

No database migration is required for 03.02 because this step establishes reusable domain architecture without introducing a concrete persisted business aggregate/table.
