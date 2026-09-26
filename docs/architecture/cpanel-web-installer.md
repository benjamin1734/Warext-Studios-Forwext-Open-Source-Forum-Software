# cPanel-first web installer

Status: **Implemented for roadmap 20.01**

Forwext full release ZIPs can be installed from the browser without Composer, npm, Node.js, SSH, Redis, Docker or Supervisor. The web installer remains PHP 8.4+ only and uses the same migration, secret-storage, permission, module and theme domains as normal runtime code.

## Installation flow

The browser flow at `/install.php` performs these stages in order:

1. **Preflight** — PHP 8.4+, OpenSSL, PDO/PDO MySQL, JSON, bundled production autoload, writable non-symlink `config/` and `storage/`.
2. **Site information** — canonical URL, site name/description, locale and IANA timezone.
3. **Database** — MySQL/MariaDB connection verification followed by all registered core migrations.
4. **First administrator** — a real active user and password credential are created, a protected administrator role and registered primary group are assigned, and the current `administrator` permission template is copied to direct user permission rules.
5. **Mail** — disabled or SMTP configuration is written to generated config; SMTP and database passwords remain in the encrypted secret store.
6. **Modules** — the installer validates selected first-party modules against the registered dependency/conflict graph before persisting enabled/disabled states.
7. **Theme** — the selected installer preset creates/publishes an initial revision through the normal theme repository and compiles the revision cache.
8. **Post-install health** — runtime, database and writable-directory checks must complete without an unhealthy result before the installed-version state is written.

The installed-version file is deliberately written **last**. A failure after schema migration therefore does not falsely lock the site as installed. Migration history makes migration retry safe; administrator bootstrap recognizes only its own already-authorized interrupted state and rejects databases containing unrelated users.

## Security boundary

- The installer uses a session-bound CSRF token, strict session cookie policy, no-store responses, restrictive CSP, no referrer and disabled camera/microphone/geolocation permissions.
- Database and SMTP passwords are never emitted back into HTML and never stored as plaintext generated config.
- The master key is sourced from `FORWEXT_MASTER_KEY` when provided or initialized in the protected cPanel-safe `config/secret.key` fallback.
- Existing user data without a valid interrupted-bootstrap administrator causes installation to fail closed instead of being adopted.
- The first administrator gets permissions from the migration-backed template in force for that exact release; the installer does not maintain a separate hard-coded ACP privilege list.
- Module dependencies/conflicts are validated server-side. Checkbox visibility is not authorization or validation.
- Theme creation uses the revision-backed theme store rather than writing arbitrary template files from request input.
- Permanent installation state is atomic and is created only after post-install health verification.

## cPanel cron instructions

Installation itself does not execute shell commands. After a successful web install, the completion page shows a command suitable for the cPanel **Cron Jobs** UI for the bounded webhook worker:

```text
php /absolute/path/to/forwext/bin/webhook-worker.php 25
```

A one-minute schedule is recommended. Hosts that expose versioned CLI PHP binaries can replace only the leading `php` executable with their PHP 8.4+ path.

The worker exits after a bounded batch and therefore does not require Supervisor or a long-running daemon.

## Release/package expectation

Use the immutable GitHub Release `full.zip`, not GitHub's source-code archive. The full package contains the production `vendor/` tree required by the web installer. Generated config, master keys, encrypted secrets and installed-version state are site-local mutable data and are not source-controlled release configuration.
