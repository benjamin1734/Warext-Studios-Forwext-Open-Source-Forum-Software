# Forwext Clean-Room & Intellectual Property Policy

Status: **Normative for the Forwext 1.x development line**  
Roadmap step: **01.04 — Clean-room ve fikrî mülkiyet sınırları**

Forwext is an independent forum platform developed by Warext Studios. Other forum products may be observed to understand user expectations, feature coverage and behavioral trade-offs, but Forwext must be implemented from its own specifications, domain model, source code, templates, phrases, database design and assets.

This policy applies to maintainers, contributors, contractors, AI-assisted work, generated code, documentation, migrations, tests, fixtures, UI assets and third-party submissions merged into the official repository.

## 1. Core clean-room rule

XenForo, MyBB and other forum/community products may be used only as **behavioral references** for Forwext development unless a separately reviewed third-party dependency is explicitly accepted under the 01.05 dependency/license policy.

Behavioral reference means learning what a user-visible feature does, what problem it solves, what workflows exist, which edge cases are observable and what architectural lessons can be derived without copying the implementation.

Forwext must independently define:

- PHP/TypeScript source code;
- class, namespace and internal identifier choices;
- database tables, columns, indexes and migration structure;
- templates and component markup;
- phrases, help text and documentation wording;
- CSS, JavaScript and frontend assets;
- icons, images, illustrations and bundled media;
- APIs, routes, event names and extension contracts;
- tests and fixtures.

## 2. Allowed reference activity

The following are allowed when performed lawfully:

- using a product through its normal user/admin interface;
- reading public product documentation and public feature descriptions;
- recording neutral behavioral observations in original wording;
- comparing workflows, usability strengths/weaknesses and missing capabilities;
- writing independent acceptance criteria from observed behavior;
- testing public HTTP/API behavior that the tester is authorized to access;
- studying public standards, RFCs and general engineering patterns;
- studying libraries/frameworks that are intentionally adopted and license-approved under the dependency policy.

Allowed observations must be converted into Forwext-native requirements. They must not preserve another product's proprietary implementation details merely because those details were visible to the observer.

## 3. Prohibited copying and derivation

The following may not be copied, adapted, translated, mechanically transformed or used as implementation input for Forwext:

- proprietary source code or decompiled implementation;
- proprietary templates or template fragments;
- proprietary phrase/language packs or distinctive help text;
- proprietary database schemas, schema dumps or migration definitions;
- proprietary icons, images, stylesheets, JavaScript bundles or other assets;
- non-public API/internal identifiers extracted for the purpose of reproducing implementation;
- leaked, pirated, nulled or otherwise unauthorized product packages;
- proprietary tests, fixtures or generated artifacts that reveal implementation;
- code produced by an AI/code-generation system when the prompt/input contains prohibited proprietary material.

Changing variable names, translating text, reformatting code, converting PHP to another language, changing SQL syntax or asking an AI model to “rewrite” proprietary implementation does **not** make the result clean-room.

## 4. Schema and data-model boundary

Forwext may implement the same general forum concepts—users, forums, threads, posts, permissions, reports, tickets, marketplace listings and similar domain ideas—but the persistence model must be designed from Forwext requirements.

Do not reproduce another product's table/column naming, relationship layout, index strategy or serialized data format as a compatibility shortcut.

Import/migration tools added later may read a source product's data when lawfully installed by the site owner, but import compatibility is a boundary adapter. It must not force the Forwext internal schema to mirror the source product.

## 5. UI and wording boundary

Common interaction patterns are not treated as proprietary implementation, but Forwext must create its own presentation.

Allowed:

- using familiar concepts such as tabs, breadcrumbs, modal dialogs, filters and permission analyzers;
- observing that another product exposes a workflow or setting;
- designing an independently worded and styled equivalent that satisfies Forwext requirements.

Not allowed:

- copying distinctive layouts pixel-for-pixel as a shortcut;
- copying proprietary HTML/template structures;
- copying phrases, descriptions, help text, onboarding text or error messages;
- shipping another product's icons, logos, screenshots or bundled visual assets as Forwext assets.

Competitor screenshots may be used privately for analysis when lawfully obtained, but should not be committed as product assets or documentation unless their inclusion is separately authorized and legally reviewed.

## 6. High-risk parity work

A task is **high risk** when it requests close parity with a proprietary feature and the implementer has access to implementation material beyond normal product behavior.

For high-risk work, use an enhanced clean-room process:

1. A behavior/specification note records only user-visible behavior, inputs, outputs, constraints and acceptance criteria in original wording.
2. Proprietary implementation details are excluded from the specification.
3. Implementation is written from the neutral specification and Forwext architecture contracts.
4. Review verifies independent naming, schema, templates, phrases and source structure.
5. The pull request records provenance and any public standards/dependencies used.

If clean provenance cannot be established, the code/content is not merged.

## 7. Contributor provenance requirement

Every contribution must be the contributor's original work, generated from permitted inputs, or derived from a third-party work that is explicitly license-compatible and recorded according to the 01.05 dependency policy.

A contributor must not submit code or assets they do not have the right to contribute.

Pull requests must declare:

- whether another forum/product was used only as behavioral reference;
- whether third-party source/assets were incorporated;
- the name/license/source of incorporated third-party material when applicable;
- whether AI/code-generation tools were used and whether prohibited proprietary material was supplied to them.

A false or missing provenance declaration is grounds to reject or revert the contribution.

## 8. AI-assisted development

AI assistance does not relax this policy.

Do not paste proprietary forum source, templates, phrases, schema dumps or leaked packages into an AI prompt for the purpose of generating Forwext code.

AI-generated output must be reviewed as if submitted by a human contributor. Maintainers remain responsible for originality, security, licensing and architectural compliance.

When an AI system produces output suspiciously similar to known third-party implementation, the output must be discarded or independently rewritten from a neutral Forwext specification.

## 9. Third-party dependencies are separate from behavioral references

A dependency intentionally included in Forwext is not governed solely by this clean-room rule. It must pass the license, NOTICE, security, maintenance and compatibility requirements in `docs/legal/licensing-and-dependency-policy.md` and be recorded in `docs/standards/dependency-inventory.json` before redistribution.

Public availability alone is never sufficient approval to vendor third-party implementation code.

## 10. Security research and vulnerability information

Publicly disclosed vulnerabilities may be studied to understand classes of failure and required defenses.

Do not copy exploit kits, leaked patches or proprietary fixed code into the product. Implement mitigations independently from the vulnerability description, standards and Forwext architecture.

Security tests should target Forwext behavior and must not embed third-party proprietary payloads/assets when an equivalent original fixture can be created.

## 11. Review checklist

Before merging a feature influenced by another product, reviewers must answer:

- [ ] Is the requirement expressed in original, behavior-focused language?
- [ ] Is the implementation written against Forwext domain/architecture contracts?
- [ ] Are source structure, identifiers and schema independently designed?
- [ ] Are templates, phrases and visual assets original or separately license-approved?
- [ ] Is any third-party material recorded with source/license provenance?
- [ ] Was prohibited proprietary material excluded from AI/code-generation inputs?
- [ ] Could the feature be explained and maintained without access to the reference product's source?
- [ ] Does the PR provenance declaration match the actual inputs used?

A “no” or unknown answer blocks merge until resolved.

## 12. Enforcement

This policy is an acceptance criterion, not optional guidance.

Maintainers may reject, remove or independently rewrite contributions with unclear provenance. Future CI/repository tooling may validate required provenance metadata, but automation does not replace human review.

At roadmap step 01.04 there is no runtime/database behavior to migrate. Completion is represented by this normative policy, the machine-readable policy manifest and repository contribution/PR rules that make the boundary visible before implementation begins.
