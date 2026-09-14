# Forwext Application Kernel & Dependency Injection Contract

Status: **Normative implementation baseline**  
Roadmap step: **02.03 — Application kernel ve DI container**

## Container responsibilities

The core container is the single service-resolution primitive for core/application composition at this stage. It provides:

- explicit bindings from service IDs/interfaces to concrete classes or factories;
- transient and singleton lifetimes;
- lazy factory execution (factories are not executed at registration time);
- constructor autowiring for unambiguous class dependencies;
- circular-dependency detection with a resolution path;
- fail-closed handling for unresolvable scalar/intersection/ambiguous dependencies;
- explicit instances;
- controlled test overrides through `Container::forTesting()` only.

A normal production container rejects rebinding/override of an already registered service. This prevents provider order from silently replacing security-critical services. Tests receive an override-enabled container deliberately.

## Autowiring boundary

Autowiring is convenience, not hidden configuration.

The container resolves class-typed constructor dependencies. Built-in scalar dependencies require an explicit binding/factory or a constructor default value. Ambiguous union/intersection dependencies fail and must be bound deliberately.

Factories receive the container only when explicitly registered; merely adding a constructor does not execute a service until it is requested.

## Lifetime semantics

- `Transient`: resolve/build a new value each time.
- `Singleton`: resolve once on first request and retain the result for the container lifetime.

Request/job scopes are intentionally not invented before the HTTP/runtime scope model exists. A later step may add scoped lifetimes without changing the meaning of transient/singleton.

## Application kernel

`ApplicationKernel` owns boot sequencing and provider registration.

Kernel sequence:

1. state starts `Created`;
2. all providers run `register()`;
3. only after every provider registered, providers run `boot()`;
4. successful state becomes `Booted`;
5. an exception during registration/boot sets state `Failed` and is rethrown;
6. successful repeated `boot()` is idempotent;
7. providers cannot be added after boot begins;
8. termination moves the kernel to `Terminated`.

Register-before-boot ordering prevents a provider from depending on a later provider merely because of an accidental boot order.

## Security and extension boundary

The DI container is not an authorization system. Resolving a service never grants permission to call its protected operations. Domain/application services must still enforce the shared permission/security contracts at the appropriate boundaries.

Third-party add-ons will receive documented extension mechanisms later; arbitrary production rebinding of core security services is not exposed as an unrestricted add-on capability by this step.

Service provider registration must remain deterministic and auditable as module/add-on lifecycle work is added.

## Testing contract

Tests cover lazy singleton behavior, transient lifecycle, constructor autowiring, circular dependency detection, unresolvable scalar failure, production override rejection, test override replacement and kernel provider ordering/idempotence.

No database migration is required for this step.
