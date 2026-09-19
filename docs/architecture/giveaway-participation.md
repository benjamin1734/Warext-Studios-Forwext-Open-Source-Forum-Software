# Giveaway participation and eligibility

Substep 13.04 adds real participation to the 13.03 giveaway lifecycle. Winner selection is intentionally not part of this subsystem.

## Eligibility policy

Each giveaway can define:

- minimum account age in days;
- minimum visible, non-deleted post count;
- optional verified/active-account requirement;
- an optional allow-list of roles (matching any configured role is sufficient);
- no referral condition, a qualified inbound referral, or a minimum number of qualified outbound referrals;
- privacy-safe per-network and per-device-signal account limits.

The policy is editable only while the giveaway is still draft or genuinely scheduled before its start time. Once the start time has arrived, eligibility cannot be weakened or strengthened mid-event.

## Participation guarantees

Participation requires both giveaway.view and giveaway.enter in the backend. The giveaway owner cannot enter their own giveaway.

A giveaway stores one participation row per user. That row carries the giveaway's configured entry_count, so repeated submissions are idempotent and cannot manufacture additional chances. max_participants counts distinct users, not weighted entry units.

The service serializes entry creation by locking the giveaway row before duplicate, fingerprint, and capacity checks. This prevents parallel requests from bypassing max participant or duplicate-account limits.

## Privacy and anti-abuse

Raw IP addresses and User-Agent strings are never stored in giveaway tables. The web layer derives HMAC-SHA256 signals with the existing installation registration fingerprint secret. Network signals are based on the packed IP; the device signal is scoped to the network plus User-Agent so a generic browser User-Agent alone is not treated as a globally unique device identifier.

The database stores only 64-character HMAC fingerprints. Audit snapshots for policy changes contain thresholds and role counts only; they do not contain raw network/device data or fingerprints.

Defaults are intentionally conservative: verified account required, up to three participating accounts per network, and one account per network-scoped device signal. Administrators can set a duplicate threshold to zero to disable that particular signal.

## Referral integration

Referral eligibility reads the canonical referral attribution tables created in 13.02. It does not issue referral rewards and does not create a second referral ledger.

## Deferred boundary

13.05 will consume the immutable participation population for cryptographically secure winner selection, draw records, redraw policy, proof/audit display, and winner notification. 13.08 remains responsible for common reward-provider fulfillment.
