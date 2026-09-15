# Content and System Permission Namespaces

Roadmap step 05.06 makes the shared 05.02 permission engine the common authorization boundary for Forwext first-party systems. Permission names use a stable lowercase dotted form, normally `namespace.resource.action`. The top-level namespace is derived from the first segment and is used for cataloging, ACP filtering and integration boundaries; it does not alter permission precedence.

## First-party namespace contract

The core catalog registers **83 typed permission definitions across 28 namespaces**:

- `forum` — forum/content viewing and creation plus numeric content limits
- `moderation` — moderation workspace access and management
- `audit` — independent moderation audit viewing, review and export
- `acp` — administration access and management
- `profile` — custom profile URL and profile-music capabilities
- `support` — ticket ownership, staff visibility and management
- `faq` — FAQ visibility and management
- `bug` — bug-report ownership, staff visibility and management
- `portfolio` — portfolio visibility and owner/staff management
- `invite` — invitation creation, own activity and management
- `referral` — referral attribution visibility and campaign management
- `ai` — AI assistance, moderation and provider/policy management
- `spellcheck` — spellcheck use and personal/site dictionaries
- `content_manager` — user-content management access and execution
- `freshness` — own-thread renewal, review and freshness-policy management
- `giveaway` — visibility, entry, creation and management
- `easteregg` — Easter Egg administration
- `trophy` — trophy/badge visibility, management and awarding
- `promotion` — automatic user-promotion policy management
- `reward` — shared reward-provider policy management
- `marketplace` — listings, external-sale mode, native purchase and order management
- `payment` — payment operations and refunds/cancellations
- `subscription` — user upgrades/subscriptions, purchase and management
- `ads` — advertising placements and targeting management
- `notice` — site notices/announcement management
- `analytics` — own/forum/site analytics and report export
- `appearance` — standard and advanced appearance administration
- `api` — first-party API use, own token management and API/webhook administration

Registering a permission definition **never grants it**. Definitions describe capabilities. Effective authorization still comes only from global/node group, role or direct-user rules resolved by `PermissionEngine`. A newly registered capability with no applicable rule remains implicit-deny.

This lets later roadmap systems adopt stable permission names before their domains exist without introducing ad-hoc role checks. When those systems are implemented, their backend services and handlers must call `PermissionAuthorizer` (or a narrow typed adapter around it) for every protected operation. UI hiding remains presentation only and is never an authorization boundary.

## Common authorization API

`PermissionAuthorizer` accepts a user id, permission key and optional node id. It resolves current primary/secondary groups and roles through `UserAccessAssignmentProvider` and delegates the final decision to the existing `PermissionEngine`.

The 05.02 precedence remains authoritative:

1. node-specific direct-user override
2. global direct-user override
3. node-specific group/role rules
4. global group/role rules
5. implicit deny

Missing assignments, assignment-loading failures, unknown definitions and permission-repository failures deny by default. The authorizer does not duplicate precedence logic and therefore cannot drift from the permission analyzer or runtime engine.

## Existing first-party runtime bridges

Profile music and custom profile URLs were implemented before the common permission engine and intentionally expose narrow permission-resolver interfaces. Step 05.06 replaces the temporary native-web baseline resolvers with:

- `EngineProfileMusicPermissionResolver`
- `EngineProfileUrlPermissionResolver`

Both delegate to the same `PermissionAuthorizer` used by future first-party modules. Existing stable keys are preserved:

- `profile.custom_url.use`
- `profile.music.use`
- `profile.music.upload`
- `profile.music.external`
- `profile.music.autoplay`
- `profile.music.moderate`

No profile domain service or HTTP handler needs its own group/role implementation.

The legacy `profile_music.permissions.*` and `profile_url.permissions.use` values remain in default/generated configuration for backwards-compatible configuration loading during pre-release upgrades, but the native `WebApplicationFactory` no longer reads them as authorization policy after 05.06. The shared permission engine is the single runtime authorization source.

## Existing-account compatibility

Role/group administration and automatic primary-group assignment are completed through later operational surfaces, while existing installations may contain real users created before 05.x access assignments existed. `DatabaseUserAccessAssignmentProvider` therefore includes one narrow compatibility state:

- it validates that the requested user actually exists in `forwext_users`;
- a nonexistent user receives no assignment and is denied;
- a real user without a primary-group row receives synthetic primary group `system:unassigned`;
- that synthetic group receives only the previous safe profile defaults: custom URL and music use/upload/autoplay allowed, external music and music moderation denied;
- it receives no ACP, moderation, audit, support, bug, marketplace, payment, analytics, AI or other newly registered capability;
- as soon as a real primary group exists, the synthetic compatibility group is not used.

The five built-in permission templates receive equivalent profile rules so applying a normal starter profile does not unexpectedly remove previously available profile features. Moderator/administrator templates may moderate profile music. External profile music remains denied by default and still requires both an explicit permission grant and the existing exact-host external-media allowlist.

## Persistence and installation

Migration `20260915235900_permission_namespaces`:

- idempotently upserts all 83 first-party permission definitions into `forwext_permissions`;
- seeds only the six narrow `system:unassigned` compatibility rules;
- adds six profile rules to each of the five built-in starter templates (30 rules total);
- uses named parameters for dynamic values;
- verifies that the complete catalog, compatibility set and starter-profile bridge rules exist;
- is explicitly registered in `CoreMigrationRegistry`, so clean browser installs and upgrades cannot silently omit it.

The namespace itself is a typed code-level contract derived from the persisted permission key. A separate namespace table is intentionally unnecessary at this stage: permission definitions are the persisted source, while namespace grouping is deterministic and cannot become inconsistent with a key.

## Extension boundary

These first-party namespace names are reserved by Forwext core. Third-party add-on permission registration is finalized in Main Step 18 and must not overwrite a core permission key. REST token scopes introduced in Main Step 19 may further restrict an API credential, but they do not replace user/group/role authorization; API execution must satisfy both the credential scope and the common permission decision.
