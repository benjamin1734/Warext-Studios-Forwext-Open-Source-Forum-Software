# Permission templates and starter profiles

Status: **Implemented for roadmap 05.03**

Forwext permission templates are starter profiles, not permanently linked policy objects. Applying a template copies/upserts only the permissions declared by that template into the target subject's normal global permission rules. Administrators can then customize those rules with the regular permission engine; unrelated existing permission keys are preserved.

## Built-in starter profiles

Fresh/upgraded installations seed five protected system templates:

- `new_user` — safe starter profile for newly registered accounts.
- `member` — standard forum-member permissions and limits.
- `verified` — member permissions with a higher content limit.
- `moderator` — forum/moderation permissions while ACP access remains denied.
- `administrator` — forum, moderation and ACP access baseline.

The seed also registers the minimal core permission definitions needed by these profiles: forum view/thread/post creation, a numeric daily content limit, moderation access/manage and ACP access/manage. Roadmap 05.06 expands first-party system namespaces on the same engine.

## Application semantics

`PermissionTemplateApplier` loads a typed template and delegates to a transactional rule writer. `DatabasePermissionTemplateRuleWriter` applies every template rule using parameterized upserts into `forwext_permission_global_rules`.

Applying a template:

1. updates/inserts only permission keys contained in that template;
2. never deletes unrelated custom rules;
3. executes the complete copy operation inside one database transaction;
4. leaves no live template dependency after the copy, so subsequent customization is direct and predictable;
5. fails instead of silently doing nothing when the requested template does not exist.

Reapplying the same template intentionally restores that template's values for the keys it owns while preserving unrelated rules. This gives ACP/UI code a predictable reset-to-profile operation later without making the permission engine depend on ACP.

## Security and data integrity

- Template keys and permission keys use bounded lowercase ASCII value objects.
- Duplicate permission keys inside one domain template are rejected.
- Numeric `allow` rules require a non-negative numeric value; flag permissions cannot carry numeric values.
- Template rule rows reference both the template and permission definition through foreign keys.
- System profiles are explicitly marked so later ACP management can protect defaults and offer clone/customize workflows rather than destructive edits.
- Applying a preset is backend state mutation and must be permission/CSRF/audit protected by the future ACP endpoint; this step provides the service boundary rather than treating a one-click UI button as authorization.

## Deployment

No new runtime service or extension is introduced. Templates use the existing MySQL/MariaDB migration engine and transaction-capable database executor, keeping the minimum cPanel deployment profile unchanged.
