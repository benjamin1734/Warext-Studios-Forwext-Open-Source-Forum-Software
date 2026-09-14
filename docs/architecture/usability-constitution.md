# Forwext Usability Constitution

Status: **Normative for the Forwext 1.x product line**  
Roadmap step: **01.02 — Kullanım Kolaylığı Anayasası**

This document defines the mandatory usability rules that every Forwext core screen, first-party system, ACP page, module, add-on-facing administration surface, native PHP frontend feature and official React/Next.js surface must follow.

The goal is not to reduce capability. Forwext may be deep and highly configurable, but the default experience must remain understandable to a first-time administrator or member without requiring knowledge of the internal architecture.

## 1. Core rule: simple by default, deep on demand

Every complex surface must follow progressive disclosure.

The default view exposes only the controls required for the most common successful task. Less common, risky, infrastructure-specific or expert controls belong behind an explicit **Advanced** layer, expandable section, secondary panel or equivalent adaptive pattern.

A setting must not be classified as Basic merely because it is frequently implemented. It is Basic only when a typical administrator is likely to need it to complete the task safely.

Advanced options may never be used to hide security-critical consequences. If an option changes authorization, privacy, destructive behavior, public visibility, payment behavior, data retention or external data transmission, the consequence must be visible at the point of action.

## 2. Basic / Advanced contract

For every ACP page or complex configuration screen:

### Basic view

Basic view should contain, when relevant:

- the feature enable/disable state;
- the primary behavior or preset;
- the most common limits;
- visibility/audience at a human-readable level;
- save/apply action;
- concise inline explanation;
- preview when visual or behavioral output can be previewed;
- a clear route to Advanced settings.

Basic view must avoid implementation jargon such as driver class names, queue backends, raw permission bitmasks, internal identifiers or serialized configuration.

### Advanced view

Advanced view may expose:

- granular thresholds;
- provider/driver selection;
- inheritance and overrides;
- detailed scheduling;
- performance tuning;
- compatibility behavior;
- per-device/per-node conditions;
- low-level integration options;
- developer-facing extension settings.

Opening Advanced view must never silently change values.

The UI must preserve the user's chosen Basic/Advanced preference where reasonable, but links from help/search may deep-link directly to an advanced control.

## 3. Safe defaults are mandatory

Every configurable feature must have an explicit default. Missing configuration must never produce an accidental insecure or destructive state.

Default values must be selected using this priority:

1. security and privacy;
2. data preservation;
3. predictable behavior;
4. shared-hosting compatibility;
5. accessibility;
6. performance;
7. convenience.

Examples of required default behavior:

- destructive actions are not enabled implicitly;
- private content is not exposed through search, analytics, API, widgets or previews;
- external network calls are opt-in where they transmit user/content data;
- advanced infrastructure is optional and falls back safely;
- animation respects reduced-motion preferences;
- media autoplay follows browser policy and must not create an unusable page;
- update/install behavior preserves user data unless a clearly separate destructive action is confirmed.

A first-party feature must document its defaults in the same change that introduces the feature.

## 4. Explanation and help contract

Every non-obvious setting must have a plain-language explanation answering:

- what this changes;
- who/what it affects;
- when it takes effect;
- whether it has a security, privacy, permission, performance, payment or data-retention consequence.

Descriptions must explain consequences, not merely restate labels.

Tooltips are supplementary; information required to use a control safely must remain available on touch/mobile and must not exist only on hover.

Error messages must state the problem and the corrective action where one is known. Internal exception names, SQL errors, stack traces and secret-bearing diagnostics are never normal end-user help text.

## 5. Preview contract

A preview is required when an administrator is configuring a visual result or a behavior whose effect can be represented safely before publishing.

Examples include:

- theme/design tokens;
- role colors, gradients, icons, banners, patterns and animation;
- notices/ads;
- layout/widget placement;
- profile appearance;
- email/notification templates where safe;
- editor/rendering transformations;
- responsive desktop/mobile appearance.

Preview must be isolated from live state. Previewing must not create public content, send notifications, charge money, dispatch webhooks or alter permissions.

When a safe preview is technically impossible, the UI must say so and use a dry-run, summary or explicit impact review instead.

## 6. Undo, reset and rollback contract

Configuration changes must support the strongest recovery mechanism practical for the domain.

Use the following hierarchy:

1. **Undo** for immediate reversible UI/session changes.
2. **Reset to default** for individual settings or configuration groups.
3. **Revision history** for durable configuration/content where prior states matter.
4. **Rollback** for published layouts/themes/configuration revisions and upgrade operations.
5. **Compensating action** for operations that cannot safely be reversed directly.

Reset/rollback actions must show the scope of what will change before execution.

Destructive actions such as permanent deletion, key invalidation, payment/refund operations, data purge or irreversible migrations may not be presented as ordinary Undo.

## 7. Dangerous-action pattern

A dangerous action requires all of the following when relevant:

- backend permission check;
- CSRF/re-auth/sensitive-action challenge according to risk;
- explicit object/scope identification;
- explanation of consequence;
- confirmation proportional to risk;
- audit event;
- safe idempotency or duplicate-submit protection;
- success/failure feedback.

High-impact bulk operations must provide a dry-run or impact summary when technically possible.

Confirmation dialogs must not be used for routine harmless actions merely to create friction.

## 8. Permission-aware usability

Hiding a button is not authorization.

All protected actions must be enforced by the shared backend permission engine. UI visibility follows the backend decision and may never be the only control.

Administration surfaces must make authorization understandable:

- show the effective permission state;
- distinguish inherited, allowed, denied and overridden values;
- show numeric limits in human-readable form;
- link to the permission analyzer where a result is non-obvious;
- never imply access solely from a role/banner visual state.

When an administrator asks “why can this user do this?”, the final ACP must be able to explain the evaluation path without requiring database inspection.

## 9. Presets and guided setup

Complex systems should offer safe presets that produce a complete valid configuration.

A preset:

- is a starting point, not a hidden mode;
- must show which values it changes;
- may be customized afterward;
- must not overwrite unrelated custom settings;
- must have a safe default preset;
- must not grant broader permissions than its label implies.

Setup assistants should use task language (“Who can create tickets?”) instead of architecture language (“Select ACL namespace”).

## 10. Search and discoverability

ACP settings and major administration functions must be discoverable through global/admin search when the ACP search layer exists.

Search metadata should include:

- human label;
- common synonyms;
- short description;
- section/path;
- required permission;
- Basic/Advanced classification.

Search results may navigate to an inaccessible page only when the product explicitly explains that the user lacks permission; they must not leak protected values.

## 11. Mobile, touch and responsive behavior

Every official frontend and ACP surface must remain usable on supported mobile widths.

Required principles:

- no required action is hover-only;
- controls meet reasonable touch-target expectations;
- tables with essential actions have a responsive representation;
- long advanced forms are grouped, searchable or sectioned;
- previews may use device modes where useful;
- sticky/fixed UI must not cover primary actions or form errors;
- the simplest successful path must remain available on mobile.

Desktop and mobile may use different presentation patterns while preserving the same security and domain rules.

## 12. Accessibility and motion

Official interfaces target practical WCAG-aligned accessibility throughout development.

At minimum:

- keyboard operation for interactive controls;
- visible focus;
- semantic labels;
- sufficient text/control contrast;
- status not communicated by color alone;
- reduced-motion support;
- screen-reader-accessible errors and validation;
- no audio-only notification for critical information.

Notification sounds, profile music, animation and decorative motion must have user controls and must respect browser/platform restrictions.

## 13. Form and validation behavior

Forms must validate at the domain/backend boundary even when client-side validation exists.

Client-side validation exists for speed and clarity, not trust.

Forms should:

- preserve safe user input after recoverable errors;
- identify the exact invalid field;
- explain min/max/format constraints;
- display counters/limits where the user benefits;
- prevent ambiguous double submission;
- avoid requiring data that can be derived safely;
- use sensible defaults without fabricating consent.

Security-sensitive validation messages must not reveal whether a protected object, account, secret or credential exists when disclosure would create enumeration risk.

## 14. State, save and publish semantics

Controls must use consistent state language.

- **Save** stores the current valid configuration.
- **Publish** makes a staged/revisioned presentation or content change live.
- **Enable/Disable** changes runtime availability without deleting retained data.
- **Uninstall** is a lifecycle action distinct from Disable.
- **Delete/Purge** removes data and must state retention consequences.
- **Reset** returns a defined scope to defaults.
- **Rollback** restores a prior recorded revision/version.

A screen must not use “Save” for an action that also silently deletes data, publishes unrelated changes or grants new permissions.

## 15. Performance budget for usability features

Usability helpers must not make the forum dependent on heavy client-side frameworks or continuous background work.

Requirements:

- progressive disclosure works without fetching large unrelated datasets;
- tooltips/help metadata are cacheable;
- preview workloads are bounded;
- live search/debounce avoids request floods;
- animations are optional and reduced-motion aware;
- cPanel/shared-hosting fallback remains viable.

Basic configuration pages must not require Redis, WebSocket, Node.js or persistent workers.

## 16. First-party system consistency

All first-party systems must reuse the same usability primitives instead of inventing unrelated conventions.

This includes Moderation Workspace, Independent Moderation Audit, Support, FAQ, Bug Reports, Portfolio, Referral, AI moderation, spellcheck, content manager, thread freshness, Giveaway, Easter Egg, achievements, promotions, Marketplace, upgrades, ads/notices and Analytics.

Shared patterns include:

- status badges and lifecycle language;
- assignment controls;
- filters/search;
- permission explanations;
- audit/history links;
- attachment/media behavior;
- dangerous-action confirmation;
- presets/defaults;
- empty states;
- mobile action patterns.

A first-party module may add domain-specific UX, but it must not redefine global meanings such as Allow/Deny/Inherit, Save/Publish, Enabled/Disabled or permission evaluation.

## 17. Native PHP and React/Next parity

The native PHP frontend is first-class.

React/Next.js interfaces may improve interaction speed or presentation, but must not be the only way to perform a core user/admin task unless that feature is explicitly an optional frontend-only extension.

Permission checks, validation, defaults and destructive-action policy belong to shared backend/domain services. They must not diverge between PHP and React clients.

## 18. Add-on-facing UX requirements

Public extension APIs that register settings, ACP pages, widgets or UI slots must carry enough metadata to participate in the same usability system.

At minimum, configurable controls should be able to declare:

- label and description;
- Basic/Advanced level;
- default value;
- validation rules;
- required permission;
- danger/sensitivity classification;
- preview capability when applicable;
- reset behavior;
- searchable keywords.

Third-party add-ons are not allowed to bypass backend authorization merely because they register UI through Forwext.

## 19. Security and privacy review questions

Before a usability-affecting feature is accepted, reviewers must answer:

1. What is the safe default?
2. Is any protected action enforced on the backend?
3. Could preview/search/help text leak private data?
4. Could reset/rollback restore insecure credentials or stale authorization?
5. Could a preset grant excessive permission?
6. Does the screen reveal secrets, tokens, internal errors or sensitive audit data?
7. Are destructive actions scoped and auditable?
8. Does mobile expose the same protected action safely?
9. Does optional external processing clearly disclose what data leaves the site?
10. Does failure degrade safely rather than enabling broader access?

## 20. Required acceptance checklist for future screens

A complex Forwext screen is not complete until applicable items pass:

- [ ] common path is understandable in Basic view;
- [ ] advanced controls are progressively disclosed;
- [ ] defaults are explicit and safe;
- [ ] non-obvious settings have consequence-oriented descriptions;
- [ ] preview/dry-run/impact review exists where applicable;
- [ ] reset/undo/revision/rollback exists where applicable;
- [ ] dangerous actions use risk-appropriate confirmation;
- [ ] backend permission enforcement exists;
- [ ] permission state can be explained;
- [ ] validation exists on the backend;
- [ ] protected information is not exposed by search/preview/errors;
- [ ] mobile/touch use is viable;
- [ ] keyboard/accessibility basics are covered;
- [ ] reduced-motion/audio controls are respected;
- [ ] cPanel/shared-hosting use remains viable;
- [ ] audit/history is emitted for relevant administrative or destructive actions.

## 21. Enforcement status for roadmap step 01.02

This constitution is the normative UX contract for all later roadmap work.

At step 01.02 there is no database schema or production ACP runtime yet, so no migration is required. The enforceable artifact at this stage is this normative contract plus the machine-readable policy manifest in `docs/standards/usability-policy.json`.

Later implementation steps must map concrete controls/components to this contract and add automated UI/backend tests where runtime code exists. A later feature cannot be marked complete by claiming that usability will be fixed in a final polish phase.
