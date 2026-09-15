# Permission Analyzer and Explanation UX

Roadmap step 05.04 adds an explanation layer on top of the authoritative 05.02 permission engine. The analyzer never re-implements authorization precedence and must never be used as a second authorization source. It calls `PermissionEngine::resolve()` and transforms the returned decision trace into an inspectable report.

## Goals

- Explain in plain language why a user is allowed or denied a permission.
- Show the effective numeric limit when the permission is numeric.
- Visualize the deterministic precedence chain instead of presenting a flat list of rules.
- Make inheritance, missing rules, fail-closed conditions and lower layers that were never reached visible.
- Preserve the engine's privacy/security behavior: repository exceptions and internal details are not exposed to the explanation UI.

## Layer order

The report always presents the permission chain in this order:

1. Node user override — only when a node is being evaluated.
2. Global user override.
3. Node group and role rules — only when a node is being evaluated.
4. Global group and role rules.
5. Secure fallback.

Each layer has a state: `not_applicable`, `no_rule`, `inherited`, `allowed`, `denied`, `fail_closed` or `not_reached`. Rule-level trace entries retain subject type/id, effect, node scope, numeric limit and engine outcome so a future ACP can enrich IDs with localized user/group/role display names without changing authorization semantics.

## Numeric permissions

The analyzer displays the final numeric limit returned by the engine. When multiple group/role allows participate in one membership tier, the 05.02 engine remains responsible for the most-restrictive aggregation. The analyzer only explains those contributing trace entries and the effective result.

## Failure behavior

Unknown permission definitions, malformed rules and repository failures remain deny-by-default. Exception messages, SQL details, credentials and other repository internals never become part of the report summary.

## Native PHP UX

`Forwext\App\Web\Permission\PermissionAnalysisHtml` renders the structured report as an accessible ordered layer list. The output exposes stable `data-state`, `data-effect` and `data-outcome` attributes for later ACP styling while escaping every dynamic value. The analyzer is intentionally reusable before the full ACP work in step 17; that later UI can wrap the same report with search, user/node selectors and localized labels.
