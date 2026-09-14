# storage

Mutable runtime storage. This directory is **not** a public web root and is protected from blind update replacement/deletion.

Expected future contents include cache, compiled templates, logs, queue/session/file-driver state, temporary files, generated configuration/runtime state and other site-specific mutable data according to configured drivers.

User/private media may use dedicated storage adapters/paths later; no secret/private file may become directly web-accessible merely because the shared host cannot change document root.
