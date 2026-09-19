# Giveaway system

Substep 13.03 establishes the first-party giveaway domain and its lifecycle. It intentionally does not implement entry eligibility, entry storage, winner selection, or reward settlement; those belong to later roadmap substeps.

## Domain boundary

A giveaway owns an immutable id and owner, a safe slug, title and description, a descriptive prize, participation terms, UTC start/end timestamps, per-user entry allowance, optional maximum participant count, and a typed lifecycle state.

The lifecycle states are draft, scheduled, open, closed, and cancelled. Only draft/scheduled giveaways can be rewritten through the draft editor. Open, closed, and cancelled giveaways cannot be silently rewritten as drafts. Ownership is immutable.

## Permission and audit contract

Backend authorization is authoritative. giveaway.view reads published scheduled/open/closed giveaways; giveaway.create creates and manages the actor's own giveaway; giveaway.manage manages all giveaways. giveaway.enter is reserved for the 13.04 participation implementation.

Create/update/publish/cancel mutations are written through the central audit recorder and enqueue a search lifecycle change for giveaway.item.

## Web and CSRF

The native PHP surface is GET /giveaways, GET /giveaways/{giveawayId}, and GET|POST /giveaways/manage. The management POST surface uses a dedicated giveaway CSRF purpose. HTML is escaped through the shared profile renderer, route ids are constrained to 32 lowercase hex characters, and timestamps are accepted as explicit UTC datetime-local values.

## Lifecycle maintenance

Advanced deployments register giveaway.lifecycle every minute on the maintenance queue with a bounded limit of 100.

The minimum cPanel profile does not require a worker. Index/detail/manage reads perform a bounded lifecycle read-repair, and the management screen also exposes an explicit bounded synchronization action. This keeps scheduled/open state functional without introducing an external daemon requirement.

## Deferred roadmap

13.04 adds machine-enforced participation eligibility and entry accounting. 13.05 adds auditable cryptographic winner selection. Common reward settlement remains reserved for 13.08. This substep does not create a second balance/reward engine.
