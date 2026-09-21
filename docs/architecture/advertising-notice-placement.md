# Advertising / Notice / Placement System (14.08)

## Scope

Forwext provides a first-party advertising, notice and announcement system that shares the common permission, audit, routing, user/group and analytics foundations.

The system is cPanel-safe: the native PHP frontend renders placements directly in the normal response pipeline and requires no Node.js, worker, Redis or external ad server.

## Content kinds

- `advertisement`: sponsored commercial/promotional content managed with `ads.manage`.
- `notice`: informational site notice managed with `notice.manage`.
- `announcement`: prominent announcement managed with `notice.manage`.

Creative content is stored as escaped headline/body text plus an optional destination. Arbitrary administrator HTML/JavaScript is intentionally not accepted by this subsystem.

## Placements

Core placement keys are registered in code so themes and future add-ons can target stable semantic positions:

- `notice.top`
- `page.top`
- `content.before`
- `content.after`
- `forum.thread.top`
- `forum.thread.bottom`
- `page.bottom`

Native HTML rendering is applied by global response middleware after the normal route handler returns a successful HTML response. Administration pages are excluded from public placement decoration.

## Targeting

A campaign may combine any of these target dimensions:

- route-name wildcard patterns such as `forum.*`;
- forum/node ids;
- primary or secondary user-group ids;
- desktop/mobile device class;
- UTC start/end schedule.

An empty target set for a dimension means no restriction for that dimension. If several dimensions are configured they all must match.

Thread routes resolve their owning forum through the first-party thread repository, so forum targeting also works when the current route contains only a thread id.

## Frequency controls

Campaigns may define an impression cap and a rolling window in seconds. Both values are required together.

Authenticated viewers and anonymous viewers are converted into HMAC-SHA256 frequency identities. The HMAC key is a domain-separated subkey derived from the installation master key. The raw user id or anonymous cookie token is not persisted into advertising events.

Anonymous viewers receive an HttpOnly SameSite=Lax first-party token cookie used only to derive the HMAC identity.

## Click tracking

Clickable creatives link through the first-party tracked redirect endpoint:

`GET /ads/click/{campaignId}`

Stored destinations must be either a safe site-relative path or HTTPS URL without embedded credentials. External destinations are therefore never taken from request query parameters.

Rapid repeated clicks from the same frequency identity/campaign are de-duplicated within a short window for analytics purposes while the redirect still completes.

## Analytics

Impression and click events persist only bounded campaign/context metadata:

- campaign id;
- event type;
- HMAC viewer hash;
- route name;
- optional forum id;
- device class;
- UTC timestamp.

The administration UI aggregates the last 30 days of impressions, clicks, CTR and estimated revenue/value. Campaigns may assign minor-unit value per impression and per click plus a currency.

These 14.08 aggregates are the advertising subsystem's operational analytics; the broader privacy-aware event registry and BI layer begins at roadmap step 15.

## Administration

`GET|POST /admin/advertising` provides one native PHP management surface for campaign content, placement, schedule, route/forum/group/device targeting, frequency cap/window and analytics.

Human mutations use a dedicated CSRF purpose and central administration audit events. Back-end permission checks remain authoritative.

Forum and user-group targets are presented from real database records rather than requiring administrators to type internal ids.

## Persistence

Migration `20260921223000_advertising_notice_system` creates:

- `forwext_ad_campaigns`;
- `forwext_ad_route_targets`;
- `forwext_ad_forum_targets`;
- `forwext_ad_group_targets`;
- `forwext_ad_device_targets`;
- `forwext_ad_events`.

The event indexes support rolling frequency checks and campaign/time analytics without requiring a background worker.

## Permissions

- `ads.manage` — manage advertisements, placement and targeting.
- `notice.manage` — manage notices and announcements.

Built-in permission templates deny both capabilities to ordinary users and moderators and allow them to administrators.

## Failure behavior

Placement decoration is deliberately fail-open for the underlying forum page. If advertising lookup/rendering fails, the original successful page response is returned unchanged rather than converting a forum page into a 500 error.

Management, persistence and click operations do not use that decorative fail-open behavior; invalid data is rejected normally.
