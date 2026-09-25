# Events, decorators and DI extension API (18.02)

Forwext exposes a deterministic low-level extension composition foundation for core code, first-party modules and later third-party add-ons. This step does **not** auto-load arbitrary add-on PHP and does not grant an add-on permission to protected forum operations; backend authorization remains authoritative.

## Extension ownership

Every third-party contribution can carry an `ExtensionOwner`:

- `core`
- `module:<module-id>`
- `addon:Vendor/AddOn`

Canonical add-on ownership uses the same bounded `Vendor/AddOn` identity introduced in 18.01. Ownership is diagnostic and conflict metadata; it is never an authorization grant.

## Owner-aware bindings

`Container::bindExtension()` registers a normal transient/singleton binding together with its extension owner. Existing `bind()`, `singleton()` and `lazy()` remain core-owned and backward compatible.

Duplicate service IDs fail closed through the existing binding-conflict rule. Production rebinding remains forbidden; only the existing explicit testing container may override services. Extension diagnostics expose the binding target, lifetime and owner without resolving the service.

## Service decorators

`Container::decorate()` wraps an existing/autowireable service without replacing the base binding.

Rules:

- higher priority executes first;
- equal priority uses registration order;
- the same owner may register at most one decorator for one service ID;
- already-resolved instances cannot be decorated after the fact;
- decorator resolution shares the normal container resolution stack, so recursive decorator/service cycles throw the existing circular-dependency exception;
- when the service ID is a class/interface, each decorator result must still implement that type;
- a decorator with no resolvable base is reported by diagnostics and fails normally if resolved.

Decorators do not bypass permission checks performed by the decorated application service.

## Typed and named events

The existing `DomainEventDispatcher::listen()` remains compatible and gains optional priority/owner metadata. `listenTyped()` allows listeners to subscribe to a concrete `DomainEvent` class or compatible event base class/interface.

During dispatch, matching named and typed listeners are merged into one deterministic sequence: higher priority first, then registration order. Listener exceptions are not silently swallowed by this primitive; higher layers decide transaction/retry/error policy.

No queue, Redis, WebSocket or background worker is required by this synchronous event API.

## Extension graph diagnostics

`ExtensionGraphDiagnostics` combines:

- service binding target/lifetime/owner,
- ordered decorator owners/priorities,
- unresolved binding targets,
- static binding alias cycles,
- decorators without a resolvable base,
- named/typed event listener owner, target and priority.

The snapshot is read-only and does not instantiate services. Later ACP/developer tooling can render this graph without creating a second extension registry.

## Security and deployment boundaries

- DI/event registration never grants `PermissionEngine` capability.
- Production service override remains disabled.
- No `eval`, runtime source patching or proprietary template injection is introduced.
- 18.02 adds no database migration and no mandatory Composer/npm/Node/Redis/Docker/Supervisor runtime dependency.
- Add-on lifecycle state from 18.01 and backend capability registration from 18.03 remain separate concerns; an installed package is not automatically executed merely because it exists on disk.
