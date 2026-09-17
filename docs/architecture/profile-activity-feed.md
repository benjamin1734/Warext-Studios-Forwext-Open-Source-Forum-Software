# Profile Posts and Unified Activity Feed

Roadmap step 07.04 adds profile-wall posts, comments, reactions, privacy and a unified activity stream while preserving the permission and social boundaries from steps 05.x and 07.03.

## Profile-wall privacy

Each account has two independent scopes stored in `forwext_profile_activity_settings`:

- `view_scope`: who may read the profile-wall activity;
- `post_scope`: who may create a new post on that wall.

Supported values are `everyone`, `followers` and `owner_only`. The profile owner always retains access to their own wall. Staff with `profile.post.moderate` may inspect protected walls for authorized moderation work. Follower checks use the existing first-party follow relationship from 07.03; they are not duplicated in a profile-specific relation table.

If either the viewer/actor or profile owner ignores the other account, cross-user post/comment/reaction writes are rejected. Ignore remains a preference boundary and does not replace normal permission checks.

## Profile posts and comments

Profile posts and comments use separate opaque 128-bit identifiers and store source text, moderation state, deletion timestamp and creation/update timestamps. Bodies are bounded to 10,000 valid UTF-8 bytes and reject unsafe control characters.

Normal reads return only `visible` and non-deleted content. Authors may delete their own contribution. A profile owner may remove content placed on their wall. Other cross-profile moderation requires `profile.post.moderate`.

The backend permission boundary uses:

- `profile.post.view`
- `profile.post.create`
- `profile.post.comment`
- `profile.post.react`
- `profile.post.moderate`

Starter profiles explicitly own all five keys: normal interaction keys are allowed for all built-in profiles, while moderation is denied for new-user/member/verified and allowed for moderator/administrator. This prevents stale grants after starter-profile changes.

## Reactions

Profile reactions reuse the 07.03 `forwext_reaction_types` catalog instead of defining a second incompatible reaction system. A viewer has at most one reaction per profile post and changing reaction type updates that row. Disabled reaction definitions no longer contribute to displayed counts/score. A user cannot react to their own profile post.

## Unified activity feed

`DatabaseActivityFeedRepository` builds a read-only candidate stream from authoritative content tables with a SQL `UNION ALL` over:

- visible, active thread creation;
- visible, active forum-post creation;
- visible profile-post creation;
- visible profile-comment creation;
- enabled-reaction activity on visible profile posts.

Private bookmark/note tables are intentionally absent from the union and must never become an activity source.

`ActivityFeedService` does not trust the SQL candidate set as final visibility. Before returning each item it reapplies viewer-specific rules:

- ignored authors are removed;
- forum items require `forum.view` for that node;
- profile items require current profile-wall privacy access;
- referenced profile posts must still be visible and non-deleted.

The feed is therefore a projection over existing data, not a second authorization source. Pagination is applied to candidate windows and may return fewer than the requested number when many candidates are filtered for the viewer; callers must not infer hidden-item counts from this behavior.

## HTTP boundary

Native authenticated routes provide profile-post listing/creation, comment listing/creation, profile reaction summary/mutation, owner/staff delete operations, privacy settings and the unified activity feed. Mutating profile-activity routes use the dedicated `profile-activity` CSRF scope and still call the actor-bound backend services.

JSON responses contain source text only as JSON data; rendering remains the responsibility of the safe editor/BBCode pipeline when a rich HTML view is built. No profile post/comment text is treated as trusted HTML.

## Persistence and installation

Migration `20260916003000_profile_activity` creates:

- `forwext_profile_activity_settings`
- `forwext_profile_posts`
- `forwext_profile_comments`
- `forwext_profile_post_reactions`

Profile owner rows cascade on account deletion. Post/comment authors use `SET NULL` so content/audit continuity can survive account deletion where policy permits. Profile reaction rows reference the shared reaction catalog with restricted reaction-type deletion.

The migration registers five profile-activity permissions, 25 explicit starter-profile rules and is registered after `20260916002000_social_interactions` in `CoreMigrationRegistry`.
