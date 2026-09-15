# Poll and Voting System

Roadmap step 06.05 adds first-class polls attached to threads. A thread can own at most one poll, while the poll, options, participant identity and selected choices remain separate normalized records.

## Domain rules

A poll has an opaque 128-bit id, creator, question, immutable option set and creation time. Questions are bounded to 255 safe UTF-8 bytes; options are bounded to 200 bytes and polls require 2–20 unique option identities/order positions.

Selection behavior is explicit:

- `single`: exactly one option per vote;
- `multiple`: one or more options up to the configured `maxSelections` value;
- duplicate submitted option ids collapse before validation;
- an option id from another poll is rejected.

`changeVote` controls whether an existing participant may replace their selected choices. Submitting the exact same selection is idempotent and does not require change permission.

## Duration and participant limits

A poll may have a UTC closing time, a maximum participant count, both, or neither. A manual close is also supported for authorized staff. Scheduled close, manual close and participant-limit exhaustion are evaluated by the same domain rule.

Vote writes lock the poll row with `FOR UPDATE`, reload the persisted poll/options and count participants in the same transaction. This prevents the final participant slot from being overbooked by concurrent requests and prevents stale application state from bypassing a changed/closed poll.

## Voter identity and result visibility

Voter identity policy is separate from result policy:

- `open`: voter identities may be returned only when the caller also has `forum.poll.view_voters`;
- `secret`: voter identities are never copied into the result DTO, even for staff with voter-view permission.

Result policy is one of:

- `always`;
- `after_vote` for the current authenticated actor;
- `after_close` after scheduled/manual/participant-limit close.

All result access additionally requires `forum.poll.view_results` and normal forum visibility authorization.

## Authorization

06.05 adds node-scoped permissions:

- `forum.poll.create`;
- `forum.poll.vote`;
- `forum.poll.view_results`;
- `forum.poll.view_voters`;
- `forum.poll.manage`.

Poll creation on the actor's own thread requires `forum.poll.create`. Creating a poll on another user's thread additionally requires the existing `forum.thread.edit_any` capability from 06.04. Voting requires a visible thread and `forum.poll.vote`. Manual close requires `forum.poll.manage`.

The actor always comes from `PermissionGate`; request data never supplies the acting user id.

## Persistence

Migration `20260915235958_poll_system` creates:

- `forwext_polls` with a unique thread id, policy fields, duration/limit and close state;
- `forwext_poll_options` with deterministic order;
- `forwext_poll_votes` with one participant row per poll/user;
- `forwext_poll_vote_choices` for normalized single/multiple selections.

Thread deletion cascades its poll. Account deletion anonymizes creator/participant references with `SET NULL` while preserving aggregate result counts. Poll/option/vote cleanup cascades through the normalized foreign-key graph.

All five built-in permission templates own every new poll key, preventing stale privileges after template downgrades. New users may vote and view eligible result counts but cannot create polls, view open-voter identities or manage polls. Members/verified members can create, vote, view results and open-voter identities. Moderator/administrator profiles receive all poll capabilities.

## cPanel baseline

The poll system uses the existing PHP/MySQL transaction and permission infrastructure. It introduces no Node, Redis, worker, WebSocket or additional PHP-extension requirement and is included in the browser installer migration registry.

## Forward boundary

06.06 adds drafts, read tracking and watch/subscription state. Those systems must reference existing thread/poll identities rather than introducing parallel discussion identities. Notification behavior for watched polls/threads belongs to the later shared notification work and must reuse the common permission and visibility boundaries.
