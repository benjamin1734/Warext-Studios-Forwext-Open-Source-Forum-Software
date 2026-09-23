# themes

Theme packages, inheritance metadata and theme-owned presentation resources.

Themes may customize supported presentation contracts but may not bypass backend authorization or embed application business logic as a security boundary.

Runtime/generated compiled template caches belong under `storage/`, not here.

Theme work should consume the stable `--forwext-*` design-token contract. Theme overrides may alter supported presentation values only through validated appearance/theme boundaries; CSS presentation never becomes an authorization boundary.

Theme revisions, base/child inheritance and staging/publish/rollback now use the typed `Forwext\\Core\\Ui\\Theme` domain. Theme templates use escaped `{{ variable }}` placeholders and compile to protected revision-addressed PHP cache files; theme source is never evaluated as raw PHP.
