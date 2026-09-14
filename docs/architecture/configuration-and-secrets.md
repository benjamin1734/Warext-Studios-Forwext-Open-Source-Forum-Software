# Forwext Configuration, Environments & Secret Storage

Status: **Normative implementation baseline**  
Roadmap step: **02.04 — Config/environment/secret sistemi**

## Environment model

Forwext recognizes five explicit runtime profiles: `production`, `development`, `test`, `install` and `maintenance`. Aliases such as `dev`, `testing` and `prod` normalize to the canonical enum values.

Unknown environments fail closed instead of silently becoming development/debug mode.

## Configuration precedence

Configuration is merged in deterministic order:

1. source-controlled safe defaults (`config/defaults.php`);
2. generated site configuration (`config/generated.php`);
3. explicitly prefixed environment overrides (`FORWEXT_CONFIG__...`).

Nested environment paths use double underscores. Example:

`FORWEXT_CONFIG__APP__ENVIRONMENT=maintenance` → `app.environment`.

Environment decoding supports booleans, null, integers, simple floats and explicit `json:` values. Only the Forwext prefix is read; arbitrary process variables are not imported into application config.

Generated config is site-specific mutable state. Normal update packages do not overwrite it.

## Safe defaults

The default environment is production, debug is disabled and maintenance is disabled. Installer/development/test modes must be entered explicitly.

Secrets are not ordinary config values and should not be printed through configuration dumps.

## Master-key strategy

Preferred key source: `FORWEXT_MASTER_KEY` containing a base64-encoded 32-byte key.

Shared-hosting fallback: `config/secret.key`, generated explicitly and stored outside the preferred public document root with restrictive permissions. The key file is ignored by Git and protected from normal updates.

The encrypted secret-store file and its master key must never be the same file or embedded together.

## Encrypted secret store

The baseline local driver uses AES-256-GCM via OpenSSL with:

- 32-byte master key;
- fresh 12-byte IV from `random_bytes()` for every encryption;
- 16-byte authentication tag;
- versioned authenticated additional data;
- versioned payload prefix;
- advisory shared/exclusive file locking;
- temporary same-directory staging plus atomic rename;
- restrictive file permissions;
- symbolic-link rejection for key/store/lock files.

The encrypted store contains string secrets such as OAuth client secrets, Turnstile secrets, API keys and similar credentials. Database-backed/remote secret providers may be added later behind the same domain boundary.

Cryptographic failures never return partial/plaintext values.

## Secret masking

`SecretMasker` removes registered exact secret values from strings and redacts common sensitive context keys such as passwords, tokens, API keys, cookies, authorization values and client/private secrets.

Structured logging/error/debug systems added later must apply masking before persistence/output. Debug mode never grants permission to print secrets.

## Security boundaries

- Environment/config selection is not authorization.
- Secret access must still be permission/audit constrained in ACP/runtime systems added later.
- The master key is never stored inside the encrypted secret payload.
- User-controlled paths must not be accepted as secret-store paths.
- Update packages must preserve generated config, master keys and encrypted site secrets.
- Production defaults must not enable verbose debug output.

No database migration is required for 02.04 because the baseline secret store is file-backed and the database layer is not implemented yet.
