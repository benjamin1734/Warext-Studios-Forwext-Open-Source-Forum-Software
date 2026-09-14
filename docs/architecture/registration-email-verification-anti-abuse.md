# Forwext Registration, Email Verification & Anti-Abuse Contract

Status: **Normative implementation baseline**  
Roadmap step: **04.02 — Kayıt, e-posta doğrulama ve anti-abuse**

## Scope

This step owns registration policy, approval/invite-only admission, email verification, CAPTCHA/Turnstile server validation, disposable-email hooks, registration-specific rate limits and immutable terms/privacy acceptance snapshots.

Password hashing, login credentials, remember-me, recovery and login history intentionally begin in 04.03. Registration must not create a temporary plaintext or weak credential store merely to cross that roadmap boundary.

## Safe default

A fresh configuration uses `registration.mode = closed`. Public registration must not be opened until the operator has configured the intended legal-document versions and, when required, the CAPTCHA provider/site/secret settings.

Supported admission modes:

- `open`: eligible registrations become active after required email verification.
- `approval`: eligible registrations become `pending_approval` after required email verification.
- `invite_only`: a valid, unexpired, enabled invite with remaining uses must be consumed atomically.
- `closed`: registration fails before any account mutation.

## Transaction boundary

Network/provider checks occur before the database transaction so slow external calls do not hold row locks. Once those checks pass, these operations are one database transaction:

1. re-check canonical username/email uniqueness;
2. consume the invite when required;
3. create and persist the `User` aggregate;
4. record every required legal acceptance snapshot;
5. issue the email-verification token when enabled.

Database uniqueness constraints remain the final race-condition guard.

## Turnstile / CAPTCHA

`CaptchaVerifier` is provider-neutral. The first-party Cloudflare Turnstile implementation performs mandatory server-side Siteverify validation.

The implementation follows Cloudflare's current Siteverify contract: `secret` + `response`, optional `remoteip`, a UUID `idempotency_key`, maximum 2048-character response token, and additional expected hostname/action checks. Cloudflare tokens are provider-side single-use and expire after five minutes. Official reference: `https://developers.cloudflare.com/turnstile/get-started/server-side-validation/`.

The secret is loaded from the encrypted Forwext secret store and is never placed into normal generated configuration or returned to the browser.

The cPanel-safe native transport uses PHP HTTPS stream wrappers with peer/name verification and redirects disabled. Deployments where `allow_url_fopen` is disabled may inject another `TurnstileTransport`; that does not change verification policy.

## Rate limiting and privacy

Registration has independent IP and email buckets. Raw client IP addresses and email addresses are not rate-limit keys. `RegistrationFingerprint` normalizes the IP to packed network bytes or uses the canonical email key and produces an HMAC-SHA256 fingerprint with a site secret.

Rate buckets are atomically incremented in MySQL/MariaDB. Failed CAPTCHA/disposable checks still count as attempts, which prevents those providers from becoming an unlimited abuse oracle.

The fingerprint key belongs in the secret store as `registration.fingerprint_key` and must be at least 32 bytes of unpredictable secret material.

## Disposable-email hook

`DisposableEmailChecker` is an explicit policy hook. The built-in domain-set checker supports locally managed blocked domains without requiring an external service. A later provider may implement the same interface; registration logic does not depend on a vendor-specific API.

## Invites

Invite codes are generated from CSPRNG bytes and returned only at issuance. The database stores SHA-256 code digests, usage counters, maximum uses, optional expiry, disable state and creation time.

Consumption uses `SELECT ... FOR UPDATE` plus a guarded increment, preventing concurrent requests from exceeding `max_uses`.

## Email verification

Forwext generates 256-bit random email-verification tokens and returns the raw token only to the caller responsible for delivery. Persistence stores only SHA-256 digests.

A token records the allowed post-verification target (`active` or `pending_approval`), expiry and one-time consumed timestamp. Consumption is row-locked and guarded; the user must still be in `pending_email` before the account state transition is accepted.

Default local email-verification lifetime is 24 hours and is independently configurable from the five-minute Turnstile token lifetime.

## Terms and privacy acceptance

Required legal documents are server-side `LegalDocumentRequirement` records containing stable type, version and SHA-256 content digest. A registration request must affirm the exact current version.

The audit record contains user ID, document type/version, document content digest, acceptance time and privacy-preserving client fingerprint. It does not duplicate the whole document text and does not store raw client IP.

Legal acceptance records are not automatically deleted by registration maintenance.

## Maintenance

`DatabaseRegistrationMaintenance` performs bounded-batch cleanup for old consumed/expired verification tokens, old registration rate-limit buckets and expired/disabled invites retained past the configured retention interval. It deliberately never purges legal acceptance audit records.

## Permission and security boundary

Registration admission is a site policy evaluated before a normal user exists, so it is not modeled as a user permission. Administrative ability to change registration policy will later pass through the common ACP permission engine.

Controllers/UI must not expose provider secrets, raw token hashes, database details or detailed uniqueness/security errors merely because an internal service exception contains diagnostic text.

## Migration

Core migration `20260914243000_registration_security` creates:

- `forwext_registration_invites`;
- `forwext_email_verification_tokens`;
- `forwext_user_legal_acceptances`;
- `forwext_registration_rate_limits`.

No destructive reset is required.

## Acceptance status

04.02 is complete when mode enforcement, approval/invite-only behavior, server-side CAPTCHA validation, disposable-email policy hook, privacy-preserving rate limiting, legal acceptance snapshots, email-verification state transition, persistence migration, bounded maintenance and relevant unit tests are all present without critical placeholders.
