# 15.04 — Moderation, Support and Bug Analytics

## Scope

The operations analytics surface consolidates the roadmap 15.04 metrics without creating a second operational datastore. It reads the authoritative report, discipline, support, bug and central audit tables and exposes only aggregate operational metadata.

Route: `GET /admin/analytics/operations?days=7|30|90`.

Authorization is enforced by the shared backend permission engine through `analytics.view_site`. UI visibility is not an authorization boundary.

## Data sources

- Moderation report volume: `forwext_reports`.
- Moderation case lifecycle/assignment: `forwext_report_groups`.
- Warnings, restrictions, suspensions and bans: `forwext_discipline_actions`.
- Support volume, first-response/resolution timing and SLA: `forwext_support_tickets`.
- Bug lifecycle/category/assignment: `forwext_bug_reports` and `forwext_bug_report_categories`.
- Staff operational actions: metadata-only `forwext_core_audit_events` rows in moderation/support/bug scopes.

The dashboard does not copy or aggregate ticket bodies, report details, bug summaries, discipline reason text, audit before/after JSON or bug-history payload JSON.

## Time windows

The supported dashboard windows are fixed to 7, 30 and 90 UTC days. The same strict range is enforced in the handler, domain repository and snapshot model.

Support first-response averages use tickets created in the selected cohort. Support resolution averages use tickets resolved during the selected window. SLA breaches use tickets created in the selected cohort and evaluate overdue unresolved work against the current UTC time.

Bug finalization metrics use the authoritative `finalized_at_utc` field. Moderation report groups do not currently have a dedicated terminal timestamp, so their terminal-duration metric uses `updated_at_utc` when the current state is resolved/rejected. This limitation is disclosed in the UI instead of presenting the approximation as a stronger source.

## Staff workload

Staff workload combines:

- current active moderation report assignments;
- current active support assignments;
- current active bug assignments;
- selected-window discipline action count;
- selected-window central audit action count for moderation/support/bug scopes.

The displayed total is current active assignments plus selected-window audit actions. Discipline action count is shown as a moderation breakdown and is not added again to the total because the same mutation may already be represented in the central audit stream.

The workload table is an operational queue/action view. It is not a quality, productivity or staff-performance score.

## Privacy and security

- Backend authorization is mandatory.
- Responses are `private, no-store` and `noindex,nofollow`.
- No raw IP, e-mail, user-agent, URL, request body or free-form support/moderation/bug content is queried.
- Staff usernames are read from the existing user table only for authorized dashboard presentation.
- No new tracking identifier is introduced.

## Performance and deployment

Migration `20260923190000_operations_analytics_indexes` adds additive, idempotent time-window indexes to the existing authoritative tables. It does not reset or rewrite site data.

The implementation uses the normal MySQL/MariaDB query layer and native PHP frontend. It introduces no Composer, Node.js, Redis, worker, WebSocket, Docker or Supervisor requirement and therefore preserves the cPanel-first runtime profile.
