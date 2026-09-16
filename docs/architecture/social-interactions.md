# Reactions, Bookmarks, Follow and Ignore

Roadmap step 07.03 adds first-party social interactions without weakening the forum permission boundary. Reactions and bookmarks are post-scoped; follow and ignore are user-scoped. Ignore is also the source for server-side authored-content filtering.

## Reaction model

`forwext_reaction_types` is the authoritative reaction catalog. Core seeds six enabled types (`like`, `love`, `haha`, `wow`, `sad`, `angry`) with bounded integer scores. The schema and `ReactionType` support scores from -100 through 100 so future administrator-managed reaction sets can express neutral or negative score semantics without changing post-reaction rows.

A user has at most one reaction per post. Selecting another type updates that row rather than accumulating several reactions from one account. Aggregate totals and reaction score are computed by joining reaction rows to the enabled reaction catalog, so a disabled reaction does not keep contributing to the displayed active score.

Reaction writes require:

- a visible, non-deleted post;
- a visible parent thread;
- `forum.view` on the source forum;
- node-scoped `forum.reaction.use`;
- an enabled reaction type;
- a post authored by somebody other than the actor.

The public reaction summary still requires `forum.view`; it is not an authorization bypass around hidden content.

## Private bookmarks

Bookmarks are keyed by `(user_id, post_id)` and may contain one private note of up to 1000 UTF-8 bytes. Notes reject unsafe control characters. Updating a bookmark replaces only that user's note.

`forum.bookmark.use` is node-scoped. Bookmark listing is private and re-checks current post/thread visibility plus the current forum permission before returning each entry. A stale bookmark therefore cannot disclose a post the owner can no longer view.

## Follow and ignore

Follow and ignore use separate global permissions:

- `social.follow.use`
- `social.ignore.use`

Self-follow and self-ignore are rejected. Follow requires an active target account. Ignore may retain a relationship to a non-active but still existing account so the viewer's filtering preference does not disappear merely because account status changed.

Ignore wins over follow. Applying ignore atomically removes an existing follow. Follow and ignore both serialize through a `FOR UPDATE` lock on the actor user row, so concurrent relationship requests cannot race into a contradictory state where the same target is both followed and ignored.

## Content filtering

`SocialInteractionService::filterIgnoredPosts()` and `filterIgnoredThreads()` remove content whose current author id belongs to the viewer's ignore set. System/anonymized content with a null author remains visible. Ignore filtering is a viewer preference only: it does not replace forum visibility, moderation state, deletion state or permission checks.

Forum/list rendering surfaces that expose authored thread/post collections must pass already-authorized collections through these filters for an authenticated viewer. Moderator/admin tools may deliberately choose an unfiltered operational view when their workflow requires it; that is a presentation decision and never changes the underlying authorization result.

## HTTP boundary

Native web routes expose:

- reaction summary and actor reaction mutation for a post;
- private bookmark create/update/delete and private bookmark list;
- follow/unfollow;
- ignore/unignore;
- a same-origin CSRF token bootstrap for interaction mutations.

Mutating routes use the dedicated `interaction` CSRF scope and still call the actor-bound `SocialInteractionService`; CSRF success never implies authorization.

## Persistence and installation

Migration `20260916002000_social_interactions` creates:

- `forwext_reaction_types`
- `forwext_post_reactions`
- `forwext_post_bookmarks`
- `forwext_user_follows`
- `forwext_user_ignores`

It seeds six core reaction definitions, registers four new permissions and gives all five built-in starter templates explicit allow rules for those normal-user interaction capabilities. Explicit template ownership prevents stale permission residue when a user's starter profile changes later.

All user/post foreign keys use cascade cleanup for interaction state. Reaction type deletion is restricted while reaction rows reference it; disabling a type is the safe operational way to retire a reaction without destroying historical rows.

The migration is registered in `CoreMigrationRegistry` after the attachment pipeline and is covered by installer-order regression tests.
