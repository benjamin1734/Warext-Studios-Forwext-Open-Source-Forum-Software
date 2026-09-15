# Forwext Authentication, Session & Recovery Contract

Status: **Normative implementation baseline**  
Roadmap step: **04.03 — Login/session/remember-me/recovery**

## Scope

04.03 owns password hashing, password credentials, password-based login, authentication-session establishment/rotation, device records, login history, rotating remember-me tokens, password reset and short-lived password confirmation.

TOTP, recovery codes, passkeys/WebAuthn, trusted-device semantics and group-enforced multi-factor policy remain 04.04.

## Password policy

`PasswordHashPolicy` validates UTF-8 text passwords without trimming or rewriting them. It rejects NUL bytes, all-whitespace values, values below the configured character minimum and values above the configured byte ceiling.

The native hasher prefers Argon2id when the PHP build exposes `PASSWORD_ARGON2ID`; otherwise it falls back to bcrypt. The cPanel baseline therefore does not require Argon2 support as a hard deployment dependency.

Default policy:

- minimum: 12 Unicode characters;
- maximum: 1024 bytes;
- Argon2id: 32768 KiB memory, time cost 3, one thread;
- bcrypt fallback: cost 12.

Password values use `SensitiveParameter`, are never logged/persisted in plaintext and are represented at rest only by PHP `password_hash()` output.

Unknown users/credentials run a dummy password verification before generic rejection to reduce identifier timing differences.

## Credential version

Each password credential has a positive `credential_version`.

- Account creation begins at version 1.
- Password hash rehashing after a successful login keeps the same version and original password-change timestamp.
- A real password change/reset increments the version.

Auth sessions and remember tokens carry the credential version. A password reset therefore invalidates old sessions lazily on their next resolve and explicitly revokes all persistent remember tokens.

## Registration integration

The 04.02 registration transaction now requires a `CredentialProvisioner`. A password-based registration cannot create a user without creating the password credential in the same database transaction.

OAuth/passwordless account creation in later roadmap steps may use a different account-provisioning path; password registration is not weakened by making credential provisioning optional.

## Authentication and enumeration resistance

`AuthenticationService` accepts username or email identifiers, but all public credential/account-state failures use the same `Authentication failed.` exception. Internal login-history outcomes distinguish invalid credentials, unavailable accounts and rate-limited attempts without exposing that distinction to the caller.

Login throttling uses **two independent fixed-window buckets**. The identity bucket is derived from the HMAC-protected submitted identifier and limits repeated attacks against one account even when source IPs change. The network bucket is derived independently from the HMAC-protected client IP and limits password spraying from one source across many account names. Both scoped bucket keys are SHA-256 hashes; neither raw identifier nor raw IP is persisted in the rate-limit table.

Default limits are 10 attempts per identity and 50 attempts per network fingerprint in 900 seconds. Both buckets are consumed on every login attempt so a rejected identity bucket does not prevent the network-abuse signal from advancing.

Raw submitted identifiers, raw IP addresses and raw User-Agent strings are not stored in login history/device tables. Only `active` accounts can authenticate normally.

## Sessions and fixation protection

Auth sessions reuse the driver-neutral `SessionStore` from 03.04. `AuthSessionManager` generates 256-bit CSPRNG session IDs prefixed with `s_`, stores a versioned JSON payload, and validates the current credential version during resolve.

Login may supply a previous session ID; establishment always creates a new session ID and removes the previous session, preventing authentication from adopting an attacker-selected pre-authentication session identifier. Authenticated session rotation likewise produces a new identifier and removes the previous one.

The database session driver was hardened to persist only `session_hash`, payload and expiry. Migration `20260914253000_authentication_runtime` removes the legacy raw `session_id` column without modifying the historical 03.04 migration fingerprint. File sessions already use a SHA-256 filename.

Cookie flags (`Secure`, `HttpOnly`, `SameSite`) remain an HTTP response/controller concern and must use the secure cookie primitive from 02.05.

## Devices

Device IDs are 128-bit CSPRNG hex identifiers. Device persistence stores only HMAC-protected User-Agent/IP fingerprints plus first/last-seen timestamps and optional revocation time. 04.03 does not mark devices as trusted; trusted-device security begins in 04.04.

A presented device ID is reused only if it belongs to the same user and is not revoked. Otherwise a new device is issued.

## Login history

Login history records optional user ID, HMAC identity/IP/device fingerprints, bounded outcome code and UTC time. It never records submitted passwords or raw network/client identifiers.

## Remember-me rotation

Persistent login tokens use independent random selector and validator values. Persistence stores SHA-256 digests only.

On successful use, the current row is locked, validated and marked consumed, then a new selector/validator is inserted in the same token family. A consumed/revoked selector seen again is treated as replay and revokes the complete family. Rotation preserves the original expiry, preventing infinite sliding extension.

`RememberAuthenticationService` restores only an account that is still active, creates a fresh auth session and returns the replacement remember token.

## Reset and confirmation

Auth challenge tokens are 256-bit CSPRNG values stored only by SHA-256 digest, with explicit purpose, expiry and one-time consumed timestamp.

Password reset consumes a `password_reset` challenge inside the same database transaction as credential replacement, increments credential version and revokes remember tokens. The new password is not expensively hashed until a valid reset token has been found, avoiding a random-token CPU amplification path. If hashing/policy validation fails, transaction rollback leaves the reset token usable.

Password confirmation verifies the current password and issues a short-lived `password_confirmation` challenge for sensitive-action flows. A challenge is purpose-bound and single-use.

A public password-reset request endpoint must give the same response whether the submitted account exists or not; `PasswordResetService::issueForUser()` is an internal post-resolution primitive and must not be exposed as an account-existence oracle.

## Maintenance

`DatabaseAuthenticationMaintenance` performs bounded cleanup of old login history, auth rate-limit buckets, expired/revoked/consumed remember-token rows and expired/consumed challenge rows. Credentials and device records are not silently deleted by this maintenance path.

## Migration

Core migration `20260914253000_authentication_runtime`:

1. removes the legacy raw `forwext_sessions.session_id` column when present;
2. creates `forwext_user_credentials`;
3. creates `forwext_user_devices`;
4. creates `forwext_login_history`;
5. creates `forwext_auth_rate_limits`;
6. creates `forwext_remember_tokens`;
7. creates `forwext_auth_challenge_tokens`.

The migration is idempotent and intentionally non-transactional because MySQL/MariaDB DDL semantics cannot be represented as a guaranteed all-or-nothing transaction.

## Acceptance status

04.03 is complete when password policy/hash/rehash, password-registration credential persistence, generic enumeration-resistant login, independent identity/network throttling, session establishment/rotation, device/history persistence, rotating replay-aware remember tokens, reset/confirmation, credential-version invalidation, hash-only token/session persistence, migration and relevant tests are all present without critical placeholders.
