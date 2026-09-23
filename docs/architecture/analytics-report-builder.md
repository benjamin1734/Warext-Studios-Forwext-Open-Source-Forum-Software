# 15.06 — Analytics Report Builder, Export and Access Control

## Scope

The analytics report builder adds bounded custom date ranges, dataset-specific filters, saved reports, CSV/JSON export, scoped analytics permissions and mandatory privacy aggregation.

Routes:

- `GET|POST /admin/analytics/reports`
- `GET /admin/analytics/reports/export`

The existing `analytics.view_site` permission remains a super-permission for compatibility. New scoped permissions allow administrators to delegate individual analytics areas without granting every business-intelligence surface.

## Scoped permissions

- `analytics.view_forum`
- `analytics.view_content`
- `analytics.view_operations`
- `analytics.view_commerce`
- `analytics.report.use`
- `analytics.export`
- `analytics.report.manage_all`
- `analytics.report.unaggregated`

Default templates remain conservative: only the administrator template receives these permissions. Existing actors with `analytics.view_site` retain access through the shared `AnalyticsAccessService`.

## Datasets and filters

The report builder exposes only fixed first-party datasets and fixed allowlisted filters:

- forum/user activity: `event_key`;
- content activity: `event_key`, `content_type`;
- moderation/support/bug operations: `scope`, `action`;
- Marketplace orders: `currency`, `order_state`, `payment_state`;
- referral funnel: `campaign`, `state`;
- giveaway participation: `state`.

Filter values are parameterized and bounded tokens. SQL table/column names never come from request input.

Date ranges are UTC calendar dates and are limited to 366 inclusive days.

## Privacy aggregation

All report datasets are aggregate queries. Each row has an aggregate `count` used as the privacy threshold.

The requested threshold is persisted with saved reports, but actors without `analytics.report.unaggregated` are forced to an effective threshold of at least 5. Rows below the effective threshold are suppressed before rendering or export, and the number of suppressed rows is disclosed without exposing their values.

No report dataset selects raw user identifiers, IP addresses, device/network fingerprints, support/bug/report free text, payment billing/receipt JSON, or central-audit before/after JSON.

## Saved reports

Saved reports persist only:

- owner;
- display name;
- dataset;
- start/end dates;
- allowlisted filter JSON;
- requested privacy threshold;
- timestamps.

Owners can update/delete their own saved reports. `analytics.report.manage_all` is required for another owner's saved report. Save/delete mutations are written to the centralized administration audit stream.

## Export

CSV and JSON exports require `analytics.export` in addition to report and dataset permissions. Export executes the report again through the same privacy enforcement path, rather than exporting cached/unverified browser data.

CSV cells beginning with spreadsheet formula markers are prefixed to prevent formula injection. Download responses use `private, no-store`, `noindex,nofollow`, `nosniff` and attachment content disposition.

## Security

- backend permission checks are mandatory;
- POST save/delete is protected by a dedicated CSRF scope;
- dataset/filter/date/threshold values are strictly validated;
- saved-report IDs are validated 128-bit lowercase hexadecimal entity IDs;
- SQL filters are parameterized and filter columns come from internal allowlists;
- no dynamic SQL table selection is exposed to the request;
- report save/delete is centrally audited;
- report pages and exports are never publicly cacheable.

## Deployment

Migration `20260923201500_analytics_report_builder` adds the saved-report table and scoped permissions without resetting existing data.

The feature uses the native PHP frontend and existing MySQL/MariaDB, permission, CSRF and audit layers. It introduces no mandatory Node.js, Redis, workers, WebSocket, Docker, SSH or Supervisor requirement.
