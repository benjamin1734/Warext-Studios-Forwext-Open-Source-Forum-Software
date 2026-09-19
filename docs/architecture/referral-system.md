# Referral and invitation attribution

Forwext treats registration-gating invitations and referral attribution as separate security domains.

## Boundaries

- Registration-gating invite codes remain owned by `RegistrationInviteStore`. They may decide whether registration is allowed.
- Referral links are public, high-entropy attribution identifiers. A missing, invalid, expired or unavailable referral must never block an otherwise valid registration.
- Referral capture writes only the referral code to a secure, host-only, HttpOnly, SameSite=Lax cookie. Registration callers pass that value as `RegistrationRequest::referralCode`.
- Registration passes HMAC fingerprints from `RegistrationFingerprint` to referral attribution. Raw IP addresses and raw user-agent strings are not persisted in referral tables.
- One referred account can have at most one attribution. Attribution is first-touch at registration time.

## Qualification and anti-fraud

A campaign defines:

- active/start/end window,
- attribution window,
- qualification delay,
- duplicate network/device thresholds,
- optional maximum qualified referrals per referrer,
- reward key and reward units.

Self-referral is rejected. Duplicate privacy-safe network/device fingerprints enter review rather than receiving an automatic reward. Pending-email and pending-approval accounts wait; only active accounts qualify automatically. Restricted or missing accounts fail closed.

Qualification is idempotent because each attribution has one state transition and one unique reward row. The cPanel-safe native staff surface can process bounded due work manually. Advanced deployments may register `ReferralMaintenanceTasks` and process `referral.qualify` every ten minutes.

## Permissions and audit

- `referral.view_own`: own campaigns, links, analytics and reward ledger.
- `invite.create`: issue an own referral link for an eligible campaign.
- `referral.manage`: campaign management, site analytics, review queue and bounded qualification processing.
- `invite.manage`: reserved for invitation-policy administration and existing registration-invite management.

Campaign changes and staff attribution decisions use the central audit stream. Frontend visibility does not replace backend authorization.

## Rewards

Step 13.02 persists a deterministic referral reward ledger (`reward_key` + units). It does not invent a second balance or role-award engine. Step 13.08 will connect referral, giveaway and trophy grants to the shared reward-provider API while preserving this ledger as the referral source record.

## Failure behavior

Notification delivery is best-effort after durable qualification/reward persistence. Referral attribution failures after a successful account registration are isolated from the registration transaction. Database and permission failures inside referral management remain hard failures.
