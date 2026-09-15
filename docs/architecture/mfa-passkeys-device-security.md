# Forwext MFA, Passkeys and Device Security Contract

Status: **Normative implementation baseline**  
Roadmap step: **04.04 — 2FA, passkey and device security**

## Scope

04.04 owns TOTP, one-time recovery codes, WebAuthn/passkeys, trusted-device credentials, MFA challenges for login and sensitive actions, and group-enforced MFA policy.

OAuth and connected accounts remain 04.05.

## Authentication gate

A correct password is only the primary authentication factor. When the resolved group policy requires MFA, Forwext does not create an authenticated session until a second factor succeeds or an explicitly permitted trusted-device credential is validated.

The pre-authentication session is revoked after successful password verification before MFA completion. Pending MFA state is represented by a short-lived, one-time challenge and does not persist a raw session identifier.

If an account is moved into an MFA-required group before it has enrolled a factor, successful primary authentication enters a bounded enrollment state instead of bypassing MFA policy or permanently locking the account out.

## TOTP

TOTP secrets are random and encrypted at rest through the Forwext AES-256-GCM `SecretCipher`. Verification uses a 30-second period and a bounded adjacent-window policy. The accepted counter is persisted atomically; a counter that has already been accepted cannot be reused.

TOTP display/enrollment secrets are exposed only during explicit enrollment and are never written to logs.

## Recovery codes

Recovery codes are generated from CSPRNG material, shown to the user only at issuance and stored only as digests. Each code is single-use. Regenerating the recovery set invalidates the previous set.

Recovery codes satisfy an MFA challenge but are not treated as a trusted-device credential.

## Group enforcement

Group MFA policies are resolved using **strictest wins** semantics. Membership in a less restrictive group cannot weaken a stronger group requirement. Policy can require MFA at login, require MFA for sensitive actions, and control whether trusted-device bypass is allowed for login.

Backend enforcement is authoritative. Hiding an MFA control in UI never grants authorization.

## Trusted devices

Trusted-device credentials use random selector/validator material and hash-only persistence. They are bound to user, device identity, credential version and expiry. Password changes or other credential-version changes make stale trust credentials invalid.

Trusted-device bypass is limited to policy-authorized login flows. Sensitive-action challenges are not silently satisfied by a trusted-device token.

## WebAuthn / passkeys

Forwext delegates WebAuthn cryptographic and ceremony validation to `web-auth/webauthn-lib` rather than implementing WebAuthn cryptography itself.

Deployment requirements:

- an explicit RP ID derived from confirmed canonical configuration;
- an explicit expected host/origin;
- CSPRNG ceremony challenges of at least 32 bytes;
- server-side challenge persistence with bounded lifetime and one-time consumption;
- `userVerification=required`;
- `attestation=none` by default to minimize unnecessary identifying data;
- credential IDs unique in persistent storage;
- updated signature counter and backup state written back after successful assertions.

The browser-supplied origin, RP ID, challenge and user-verification result are not trusted without server-side library validation.

## Sensitive actions

Sensitive operations can require a fresh challenge independently of login recency. TOTP, recovery code or passkey verification may satisfy that challenge according to policy. A successful challenge is purpose-bound, short-lived and one-time.

A normal authenticated session or a trusted-device token is not itself proof of a fresh sensitive-action challenge.

## Persistence

Migration `20260915120000_mfa_device_security` creates the TOTP, recovery-code, MFA-challenge, WebAuthn-ceremony, passkey, trusted-device, group-policy and user-group association tables required by this step.

Raw TOTP secrets, raw recovery codes, raw trusted-device validators and raw WebAuthn ceremony challenges are not persisted in plaintext where a digest or authenticated encryption is sufficient.

## Runtime dependency

`web-auth/webauthn-lib` is an approved MIT-licensed runtime dependency. Installation packages include production Composer dependencies so cPanel runtime does not require Composer.

## Acceptance status

04.04 is complete when login MFA gating, enforced enrollment, TOTP replay protection, single-use recovery codes, passkey registration/assertion, trusted devices, sensitive-action challenges, strict group enforcement, migration coverage, dependency/legal inventory and tests are present without critical placeholders.
