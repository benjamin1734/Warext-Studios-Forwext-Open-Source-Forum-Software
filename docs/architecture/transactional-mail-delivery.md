# Transactional Mail Delivery

Status: **native runtime baseline**

Forwext uses the common `core/Mail` transport contract for account-critical email. The native runtime supports `disabled`, PHP `mail()` and SMTP delivery without requiring an additional mail package.

## Account flows

Registration email verification and password reset both use `MailAuthLinkDelivery`. Raw verification/reset tokens are placed only in the destination URL and are never written into normal page output, logs or error messages. The receiving endpoints still validate token shape, expiry and one-time consumption.

When mail is disabled or incompletely configured, account flows that require outbound delivery fail closed before issuing a user-facing workflow that cannot be completed.

## SMTP security

The native SMTP transport:

- supports implicit TLS and STARTTLS with peer/name verification;
- rejects authenticated SMTP over plaintext transport;
- bounds connect/read timeouts;
- validates SMTP host, port, username and credential shape;
- uses AUTH PLAIN with LOGIN fallback after transport encryption;
- bounds multiline SMTP responses;
- dot-stuffs DATA payloads;
- never includes SMTP credentials or provider response text in thrown errors.

`none` encryption remains available only for unauthenticated local relay deployments. Administrators should prefer STARTTLS or implicit TLS for internet SMTP.

## Message safety

Mail subjects and sender display names reject control bytes. MIME bodies use UTF-8 with base64 transfer encoding, account mail includes both text and HTML alternatives, and generated headers use CRLF line endings. Recipient and sender addresses pass through the canonical `EmailAddress` value object.

The SMTP password remains in encrypted secret storage under `mail.smtp.password`. ACP surfaces display only configured/not-configured state.

## Configuration

The native runtime reads:

- `mail.driver`
- `mail.from_address`
- `mail.from_name`
- `mail.smtp.host`
- `mail.smtp.port`
- `mail.smtp.encryption`
- `mail.smtp.username`
- `mail.smtp.password_secret`
- `mail.smtp.timeout_seconds`

Missing required values degrade to an unavailable mail transport rather than exposing secrets or partially composing an unsafe transport.
