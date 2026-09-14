# Forwext Architecture & Domain Glossary

Status: **Normative for the Forwext 1.x codebase**  
Roadmap step: **01.03 — Teknik sözlük ve domain isimleri**

This glossary fixes canonical domain language before implementation expands. Database tables, PHP namespaces/classes, route names, permission namespaces, API resources, events, documentation and UI copy should use these concepts consistently unless a later architecture decision explicitly revises the contract.

## Naming rules

1. Domain names describe Forwext concepts, not names copied from another forum product.
2. A display label may be translated, but the canonical internal English concept remains stable.
3. Singular nouns name entities/resources (`User`, `Thread`, `Ticket`); plural nouns name collections.
4. `*Id` means the stable identifier of that entity; it must not imply database implementation details.
5. `status` describes lifecycle state; `type` describes a registered variant; `permission` describes authorization.
6. UI visibility never changes the domain meaning of a permission.
7. First-party `Module` and third-party `Add-on` are distinct concepts and must not be used interchangeably.
8. `Role` and `Group` are distinct even when both may contribute permissions or presentation.
9. `Report` and `Moderation Case` are distinct: a report is an input/signal; a moderation case is staff work/decision context.
10. `Widget`, `Region` and `UI Slot` are distinct: reusable block, layout area and extension insertion point.

## Identity and access

- **User**: canonical account/domain identity in Forwext. A user has lifecycle state, credentials/connected accounts, profile, preferences and authorization context.
- **Member**: presentation term for a user participating in the community. Do not create a second `Member` identity domain.
- **Group**: membership classification used for primary/secondary grouping, defaults and permission contributions. A user has one primary group and may have secondary groups.
- **Role**: assignable functional/presentation role distinct from group membership. Roles may contribute permissions and role appearance according to the shared policy engine.
- **Permission**: authorization rule evaluated by the shared permission engine. Supports global/node-scoped/numeric rules plus group/role/user contributions and deterministic allow/deny/inherit semantics.
- **Permission template**: reusable preset that initializes a coherent permission configuration and can then be customized.
- **Permission analyzer**: diagnostic that explains the effective permission result and its contributing sources.
- **User override**: explicit per-user permission contribution evaluated by the same engine; not a bypass outside the engine.
- **Authentication**: proof/establishment of user identity.
- **Authorization**: decision about what an authenticated or guest actor may do.

## Forum content

- **Node**: hierarchical site container. Registered node types include category, forum, page and link-like nodes.
- **Category**: organizational node that groups child nodes and does not itself accept ordinary threads.
- **Forum**: node that accepts threads according to its configuration and permissions.
- **Thread**: top-level discussion/content container belonging to a forum and governed by a registered thread type/lifecycle.
- **Post**: ordered message/content entry inside a thread. The first post is related to the thread but remains a real post.
- **Thread type**: registered behavior/type contract for threads without forking the core thread identity.
- **Prefix**: configured label/classification applied to eligible threads.
- **Tag**: reusable free/managed taxonomy term associated with supported content.
- **Poll**: voting object associated with supported content, normally a thread, with its own choices/rules/results.
- **Draft**: non-published user work state; it is not a published post/thread.
- **Content type**: registered domain content participating in shared services such as moderation, search, reactions, reporting, notifications and attachments.
- **Attachment**: managed media/file object associated with authorized content through the shared attachment pipeline.
- **Reaction**: typed user response to supported content; reaction score is a policy/configuration concern, not the identity of the reaction.

## Communication and support

- **Conversation**: private/direct-message container with one or more participants. It is not a forum thread.
- **Ticket**: support request container with requester, category, priority, status, assignment, SLA metadata and conversation/history.
- **Ticket message**: user/staff-visible message inside a ticket conversation.
- **Internal staff note**: staff-only ticket entry; it must never be exposed as a normal requester-visible ticket message.
- **FAQ article**: structured question/answer knowledge item searchable independently and connectable to support suggestions/drafts.

## Moderation and audit

- **Report**: a user/system signal that content/account/activity may need review. Duplicate reports may be grouped without losing reporters/history.
- **Moderation Case**: staff work context that can connect reports, flagged content, review tasks, actions, evidence and outcomes.
- **Moderation action**: permission-checked staff action affecting content, users or moderation state.
- **Audit event**: append-oriented record of a security/administrative/moderation-relevant action with actor, target, action, time/request context and redacted before/after data where applicable.
- **Independent moderation audit**: separate verifiable moderation-audit stream whose integrity rules prevent a moderator from editing/deleting their own records.
- **Approval item**: content awaiting an approval/rejection decision in the common moderation queue.
- **Warning**: disciplinary record/points rule applied to a user under permission and expiry policy.
- **Ban**: account/access restriction with temporary/permanent lifecycle semantics.

## Marketplace and revenue

- **Marketplace Listing**: seller-owned listing offered in the Forwext marketplace. A listing may use external-purchase mode or internal-purchase mode.
- **Marketplace category**: hierarchical classification for marketplace listings; distinct from forum nodes even if UI can cross-link them.
- **External purchase destination**: validated outbound destination used by an external-mode listing.
- **Cart**: buyer-owned collection of internal-purchase items pending checkout.
- **Order**: durable internal purchase record connecting buyer, seller, items, payment state and delivery lifecycle.
- **Payment provider**: pluggable implementation of the payment contract; providers do not own the Order domain.
- **Delivery**: fulfillment state/artifact for an internal order, including download, key, license or manual delivery.
- **Dispute**: order-related issue context connected to support/moderation rules; not a second ticket identity when support is used.
- **User upgrade**: purchasable/granted entitlement plan that can attach time-bounded or permanent role/permission effects.
- **Notice**: site communication placement governed by targeting/visibility/frequency rules.
- **Ad placement**: configured advertising location/targeting unit measured through the analytics system.

## Engagement and reward systems

- **Giveaway**: scheduled reward event with eligibility rules, entry lifecycle, participant limits and auditable winner selection.
- **Giveaway entry**: one user's recorded participation/eligibility state for a giveaway.
- **Reward**: abstract grant emitted by eligible systems such as referral, giveaway or achievement workflows through shared reward-provider contracts.
- **Trophy / Achievement**: rule-based earned accomplishment recorded in user history.
- **Badge**: visual/semantic award indicator; a badge may represent an achievement but is not automatically a permission role.
- **User promotion**: rule-driven change to a user's group/role/entitlement state through controlled promotion logic.
- **Referral**: attributed relationship from a referral link/campaign to a candidate/qualified referred user.
- **Easter egg**: lightweight configured surprise/trigger with scoped visibility and bounded performance impact.

## Analytics, search and automation

- **Analytics event**: privacy-aware registered measurement event. It is not an audit event and must not substitute for security logging.
- **Metric**: aggregated/calculated measurement derived from authorized analytics events/data.
- **Search document**: permission-aware indexed representation of registered content.
- **Job**: retryable asynchronous work unit.
- **Queue**: infrastructure-backed job transport/processing abstraction.
- **Scheduler**: time-based trigger registry that enqueues or executes scheduled work according to hosting capabilities.
- **Realtime transport**: polling/SSE/WebSocket-compatible delivery abstraction selected by capabilities.

## Platform architecture

- **Core**: mandatory runtime/forum functionality that cannot be uninstalled.
- **First-party module / Module**: official Forwext subsystem shipped by Warext Studios and integrated with shared core services. `Module` is the canonical short form in architecture/code when context is clear.
- **Third-party add-on / Add-on**: externally developed extension using documented public extension APIs. `Add-on` is not a synonym for first-party module.
- **Module registry**: authoritative first-party module state/dependency/capability/version registry.
- **Add-on manifest**: third-party package metadata defining ID/version/requirements/conflicts/capabilities/lifecycle metadata.
- **Entity / Domain model**: typed representation of business state and invariants.
- **DTO**: explicit data-transfer shape crossing an application/interface boundary; it is not a persistence-aware domain entity by default.
- **Repository**: query/persistence boundary for domain data.
- **Application service**: use-case orchestration boundary shared by web/API/CLI/jobs.
- **Domain service**: domain-rule operation that does not naturally belong to one entity/value object.
- **Migration**: versioned schema/data evolution step tracked by the platform and designed for safe install/upgrade behavior.
- **Driver**: infrastructure implementation behind a platform interface, such as database vs Redis cache.
- **Provider**: pluggable external-capability implementation, such as OAuth, AI, mail, payment or embed provider.
- **Capability**: detected server/environment ability used to select supported behavior/fallback.
- **Event**: typed domain/platform notification consumed by listeners without forcing direct coupling.
- **Decorator**: service extension that wraps a public service contract in deterministic order.
- **Setting**: managed configuration value with declared type/default/validation/visibility metadata.
- **Secret**: sensitive configuration value requiring protected storage/masking and stricter access rules.

## Presentation and extensibility

- **ACP**: Forwext Administration Control Panel.
- **Theme**: inheritable presentation package/design configuration.
- **Design token**: semantic/shared appearance value consumed by official frontends/components.
- **Template**: native PHP frontend presentation source compiled/cached by the platform.
- **Widget**: reusable configurable UI block placed into a layout region/slot.
- **Region**: major page-layout area such as header, main, sidebar or footer.
- **UI Slot / Slot**: named extension insertion point where modules/add-ons can register compatible UI without editing core templates.
- **Visibility rule**: server-evaluated presentation condition; it never substitutes for content authorization.
- **Layout**: ordered composition of regions/widgets/slots and their presentation conditions.
- **Preset**: named safe starting configuration whose applied values remain inspectable/customizable.
- **Revision**: stored prior version of revision-capable configuration/content used for diff/rollback/staging.

## API and compatibility

- **Public API**: compatibility-governed extension surface covered by SemVer/deprecation policy.
- **Internal API**: implementation detail without third-party compatibility guarantee.
- **Experimental API**: explicitly unstable opt-in extension point.
- **REST API v1**: versioned HTTP API rooted under `/api/v1`.
- **Webhook**: signed outbound event delivery with retry/logging/security policy.
- **SDK**: supported developer client/tooling surface built against public contracts.

## Release and operations

- **Release artifact**: generated install/update ZIP, checksum and related release metadata.
- **Full package**: complete package capable of installing the target version from zero.
- **Update package**: package that moves the immediately previous supported Forwext release to the target without resetting application data.
- **Health check**: diagnostic proving a subsystem/runtime capability can operate.
- **Integrity check**: verification that expected files/data/configuration satisfy integrity rules; distinct from health.
- **Clean-room reference**: behavior/architecture observation that does not copy proprietary implementation, schema, templates, phrases, identifiers or assets.

## Reserved naming decisions

The following internal terms are intentionally avoided as canonical synonyms:

- Do not use `Member` as a second account entity beside `User`.
- Do not call a first-party `Module` an `Add-on`.
- Do not call a `Role` a `Group` or vice versa.
- Do not call a support `Ticket` a forum `Thread`.
- Do not call an incoming `Report` a `Moderation Case`.
- Do not call an analytics event an `Audit Event`.
- Do not call a `Widget` a `Slot`.
- Do not call marketplace `Category` records forum `Node` records.

These distinctions exist to keep permissions, migrations, APIs, search, audit and extension contracts deterministic as the codebase grows.
