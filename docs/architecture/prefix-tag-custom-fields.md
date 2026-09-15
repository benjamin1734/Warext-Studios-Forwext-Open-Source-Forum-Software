# Prefixes, Tags and Custom Forum Fields

Roadmap step 06.04 adds structured thread metadata without turning prefixes, tags or custom fields into parallel authorization or content systems. The owning thread/forum remains authoritative; metadata only enriches it.

## Prefix groups and prefixes

Prefixes are configured records, never arbitrary thread text. `PrefixGroup` groups related `ThreadPrefix` records, and both have opaque 128-bit identifiers, bounded display names, deterministic sort order and an enabled flag.

Forum eligibility is explicit through `forwext_forum_prefix_groups`. `ThreadMetadataService` accepts a prefix only when the prefix and its group are enabled and the group is configured for the thread's current forum. A thread stores at most one prefix through `forwext_thread_prefix_assignments`.

Deleting an assigned prefix is restricted at the database boundary. Normal administration should disable historical prefixes instead of destroying meaning on existing threads.

## Tags and autocomplete

Tags use bounded UTF-8 names and opaque ids. `forwext_tags.name` is unique under the database's `utf8mb4_unicode_ci` collation, so persisted names have a single case-insensitive identity without requiring Mbstring for the minimum deployment profile.

Forum tag policy is fail-closed and stored in `forwext_forum_content_config`:

- tags enabled/disabled;
- new-tag creation enabled/disabled;
- maximum tag count, bounded to 0-20.

A forum with no stored metadata configuration receives `tagsEnabled=false`, `allowNewTags=false`, `maxTags=0`. Enabling a feature therefore requires an explicit administrator action.

Autocomplete requires a resolvable authorized forum and the existing node-scoped `forum.view` permission. The database query is prefix-based, bounded to 20 results, parameterized and escapes SQL LIKE wildcard characters supplied by the user. Disabled forums return no autocomplete results.

When new tags are disabled, metadata replacement resolves every requested name against existing tags under a transaction and rejects the entire update if any tag does not exist. When creation is enabled, tag insertion is protected by the unique name constraint and then resolved back to the persisted id.

## Custom fields

06.04 supports definitions targeting either `thread` or `forum`. Stable field keys use lowercase ASCII identifiers while labels and values remain UTF-8.

Supported value types are intentionally bounded:

- `text` — optional minimum/maximum byte length and a platform hard cap of 100000 bytes;
- `integer` — optional numeric minimum/maximum;
- `boolean` — strict boolean values only;
- `choice` — one configured key from an allowlist of at most 100 choices.

Definitions never execute administrator-supplied regular expressions, PHP, HTML or JavaScript as validation. Text values reject unsafe control characters. Persisted values use JSON only as a typed storage representation, and hydration fails closed if the stored JSON type does not match the definition type.

Thread-field enablement is forum-specific through `forwext_forum_thread_fields`. A write rejects unknown submitted fields, missing required configured fields and disabled/wrong-target definitions before persistence. Forum-target fields are validated by the same type model and are writable only through the ACP administration service.

## Authorization

Thread metadata mutation introduces two node-scoped permissions:

- `forum.thread.edit_own`
- `forum.thread.edit_any`

`ThreadMetadataService` derives the actor only from the actor-bound `PermissionGate`. An author may use `edit_own`; another actor requires `edit_any`. `edit_own` may fall back to an explicit `edit_any` grant for staff. All writes also require normal forum visibility authorization.

The five protected starter permission templates receive both keys so applying a lower privilege profile cannot leave an old `edit_any` grant behind:

- new user / member / verified: `edit_own=allow`, `edit_any=deny`;
- moderator / administrator: both allowed.

Metadata administration—prefix groups/prefixes, field definitions, forum metadata configuration and forum-field values—requires global `acp.manage` through `ForumMetadataAdminService`.

## Atomic persistence

`DatabaseForumMetadataRepository::replaceThreadMetadata()` replaces a thread's prefix, tag relations and custom-field values inside one database transaction. The replacement is all-or-nothing; policy failures such as a forbidden new tag roll back the transaction.

`saveConfiguration()` similarly replaces a forum's content configuration, prefix-group mapping and enabled thread-field mapping in one transaction. Forum/thread deletion cascades their metadata mappings and values. Historical prefix and field definitions use restrictive references where deleting them would invalidate existing content semantics.

## Schema

Migration `20260915235957_forum_metadata` creates eleven normalized tables:

1. `forwext_prefix_groups`
2. `forwext_thread_prefixes`
3. `forwext_forum_prefix_groups`
4. `forwext_thread_prefix_assignments`
5. `forwext_tags`
6. `forwext_thread_tags`
7. `forwext_custom_fields`
8. `forwext_forum_thread_fields`
9. `forwext_thread_custom_field_values`
10. `forwext_forum_custom_field_values`
11. `forwext_forum_content_config`

The same frozen migration registers the two new edit permissions and ten starter-template rules. Earlier permission migrations are not modified, preserving migration source integrity for existing installations.

## Forward boundaries

06.05 polls and later thread/post systems may reference the existing thread identity but must not overload prefixes/tags/custom fields into poll state or free-form executable configuration. Search/filter integration can index this metadata later, but final content visibility must always continue through the common authorization boundary.
