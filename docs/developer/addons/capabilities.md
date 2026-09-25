# Capability disclosure

The optional `capabilities` array in `addon.json` is a typed disclosure mechanism for administrators and compatibility tooling.

Supported values are:

- `database`
- `filesystem`
- `outbound_network`
- `background_jobs`
- `scheduled_tasks`
- `admin_ui`
- `user_ui`
- `content_extension`
- `permissions`
- `webhooks`

Declare only capabilities the add-on actually needs.

Capability declarations do not grant access. They do not replace backend permissions, CSRF, SSRF controls, lifecycle activation, queue ownership, route authorization or any other Forwext security boundary.

`addon:check` surfaces the declared capabilities as risk-oriented review warnings. Unknown values are rejected instead of silently ignored.
