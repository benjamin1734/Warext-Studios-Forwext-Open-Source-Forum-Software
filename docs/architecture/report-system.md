# Report System Architecture

Sub-step **09.02** adds a first-party moderation report pipeline for content that the reporting actor is already authorized to view.

## Security boundary

- Reporting requires the backend permission `report.create`.
- Client-supplied `target_type` and `target_id` never establish access. They are resolved through `ReportableContentRegistry`.
- Core thread/post resolvers re-read the target, require visible/non-deleted content, resolve its forum node and re-check `forum.view` for the current actor.
- Unknown target types and inaccessible targets fail closed and are exposed as not found rather than as an existence oracle.
- Report POSTs use the same-origin `X-Forwext-Report: 1` guard plus Origin / Sec-Fetch-Site validation.
- Moderation report mutations reuse the moderation same-origin guard and still require `moderation.manage`; report reading requires `moderation.access`.

## Data model and duplicate grouping

The migration `20260918002000_report_system` creates:

- `forwext_report_reasons`
- `forwext_report_groups`
- `forwext_reports`
- `forwext_report_comments`

An active group is keyed by the SHA-256 fingerprint of target type, target id and reason. A unique active-dedupe index makes concurrent equivalent reports converge on one moderation case. Each authenticated reporter can contribute at most one submission to an active group. Closing a group clears its active dedupe key, so a later incident can create a new case without mutating historical records.

Starter reasons are spam, harassment/insult, privacy, potentially illegal content and other.

## Workflow

User surfaces:

- `GET /reports/new?type=...&id=...` validates the target before rendering the report form.
- `POST /reports` creates or joins an active group.
- `GET /account/reports` shows only the current user's own report history and public case status; moderator comments are never exposed there.

Moderator surfaces:

- Active report groups appear inside the existing `/moderation` workspace.
- `GET /moderation/reports/{groupId}` shows grouped submissions and internal moderator notes.
- Authorized managers can assign/unassign a moderator, move an active case through open/in-review/resolved/rejected, and add internal notes.
- Assignment, status changes and internal comments append to the existing moderation audit store.

## Notifications

The existing durable notification pipeline is reused. First-party report notification definitions cover report receipt, moderator assignment and reporter-visible status changes. Dedupe keys prevent repeated identical notification emission.

## Deployment

The implementation is native PHP + MySQL/MariaDB and adds no Node, Redis, daemon, Supervisor or worker requirement to the normal cPanel runtime. The migration is additive and does not reset existing data.
