# Trophy, badge and achievement system

Substep 13.07 introduces persistent first-party recognition records. It deliberately stops at recognition: automatic role/group promotion and cross-system rewards are owned by 13.08.

## Definition model

Each definition has:

- stable internal id and unique key;
- kind: trophy, badge or achievement;
- display name and description;
- active flag and integer priority;
- optional same-origin icon and banner paths;
- rule type and threshold;
- created/updated timestamps.

Icon/banner paths must be absolute same-origin paths. Protocol-relative URLs, traversal segments, query strings, fragments and control characters are rejected.

Priority controls profile ordering; higher values are displayed first.

## Rule engine

The first deterministic rule catalog is intentionally bounded:

- account_age_days;
- visible_post_count;
- qualified_referral_count;
- giveaway_win_count;
- manual.

Metrics use existing authoritative Forwext data. Visible posts exclude deleted/non-visible posts. Referral count uses qualified 13.02 attribution state. Giveaway wins count only the latest draw in each giveaway, so a superseded redraw winner is not counted as current.

Rules grant achievements when their threshold is met. Evaluation is idempotent. A manually revoked rule grant is not silently re-awarded by later rule evaluation; an authorized manual award is required to restore it.

## Evaluation runtime

Advanced deployments can register trophy.evaluate hourly at minute 17 with a bounded 200-user batch. A persistent cursor advances through user ids and resets after the end, preventing every run from evaluating only the same first users.

The ACP also exposes an explicit evaluate-user action, which is permission checked and audited. This is the cPanel/no-worker fallback.

## Grants and history

forwext_user_trophies stores one durable grant lifecycle per user/definition. forwext_trophy_history is append-only and records award/revoke events, source, actor, reason and timestamp.

Manual award/revoke requires trophy.award. Definition changes require trophy.manage. Human mutations are written to central administration audit. Rule-engine grants have their own durable history record without inventing a fake human actor.

Revocation requires a reason. Manual awards may optionally carry a reason.

## Profile UX

Existing and new profiles receive the achievements tab. Authenticated viewers with trophy.view can see active achievements ordered by priority, optional icon/banner media and recent award/revoke history. Profile privacy still controls whether the profile/tab itself is visible.

Guests currently do not receive trophy data because the existing permission engine resolves user assignments, not anonymous principals. This keeps trophy.view backend-authoritative rather than bypassing it in presentation code.

## Administration

/admin/trophies provides:

- definition create/edit;
- active state, priority, kind, icon/banner path;
- supported rule and threshold;
- manual award/revoke by username;
- bounded single-user rule evaluation.

The route uses a dedicated trophy CSRF purpose. Separate trophy.manage and trophy.award permissions allow future delegation without conflating definition management and award authority.

## Schema

Migration 20260919170000_trophy_system creates definitions, user grants, append-only history and evaluation cursor state; seeds conservative template permissions and adds the achievements profile tab for existing stored profiles.
