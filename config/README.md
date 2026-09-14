# Forwext configuration

`defaults.php` contains source-controlled safe defaults. A real installation writes site-specific generated configuration to `config/generated.php`; that file is intentionally ignored and protected from normal updates.

Configuration precedence is:

1. source defaults;
2. generated site configuration;
3. explicitly prefixed environment overrides (`FORWEXT_CONFIG__...`).

Example: `FORWEXT_CONFIG__APP__ENVIRONMENT=maintenance` overrides `app.environment`.

Secrets do not belong in ordinary generated configuration. The master encryption key is taken from `FORWEXT_MASTER_KEY` when available; shared-hosting installs may instead use the ignored `config/secret.key` file with restrictive permissions. Encrypted secret values are stored under protected runtime storage.
