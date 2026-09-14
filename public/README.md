# public

Preferred web document root.

Only files intentionally safe for direct HTTP delivery belong here: front controller/entry points and built public assets when implemented.

Application source, secrets, private storage, logs, backups and internal configuration must remain outside the public document root. Shared-host compatibility mode must preserve this security boundary with explicit deny/forwarding rules.
