# themes

Theme packages, inheritance metadata and theme-owned presentation resources.

Themes may customize supported presentation contracts but may not bypass backend authorization or embed application business logic as a security boundary.

Runtime/generated compiled template caches belong under `storage/`, not here.

Theme work should consume the stable `--forwext-*` design-token contract. Theme overrides may alter supported presentation values only through validated appearance/theme boundaries; CSS presentation never becomes an authorization boundary.
