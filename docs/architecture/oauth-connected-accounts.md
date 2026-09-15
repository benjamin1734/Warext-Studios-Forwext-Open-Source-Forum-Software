# OAuth and connected accounts

Forwext 04.05 adds a provider-neutral OAuth 2.0 connected-account boundary with built-in Google and Discord providers.

## Security model

- Every authorization start creates a cryptographically random state value and PKCE verifier.
- Only the SHA-256 state digest is stored. State is single-use and expires after a short TTL.
- PKCE uses S256. Callback provider, exact HTTPS redirect URI and optional linking user are bound to the transaction.
- Redirect URIs must be explicitly allowlisted in provider configuration; credentials/fragments are rejected.
- Provider client secrets are read through `SecretStore`; plaintext secrets are never stored in normal config.
- Access tokens are used only to retrieve the provider identity and are never persisted by the connected-account subsystem.
- An unknown provider identity may link to an existing local account only when the provider asserts a verified email and that normalized email already belongs to the local account.
- Provider subject uniqueness and one-identity-per-provider-per-user are enforced by database unique keys.
- A login flow cannot be silently converted into a link flow and a link flow is bound to the authenticated local user.
- Suspended/banned/non-active accounts cannot bypass the normal local authentication state through OAuth.
- The last authentication method cannot be unlinked unless a password or another connected provider remains.

## Built-in providers

Google uses the OpenID Connect userinfo endpoint with `openid email profile`. Discord uses `identify email`. Both providers require PKCE S256 and a client secret held by the encrypted secret store.

## Configuration

`config/defaults.php` contains disabled-by-default Google and Discord provider entries. Operators provide a public client id, one or more exact HTTPS callback URLs and store the corresponding secret under `oauth.google.client_secret` or `oauth.discord.client_secret`.

OAuth stays disabled until an operator explicitly enables a provider. This makes an incomplete or copied configuration fail closed.

## Persistence

`forwext_connected_accounts` stores provider subject, local user ownership and minimal display/email metadata. `forwext_oauth_transactions` stores only short-lived authorization transaction data. Provider access/refresh tokens are deliberately not stored in this step.

The migration is idempotent and registered in the core installer/upgrade registry.
