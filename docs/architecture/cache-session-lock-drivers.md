# Forwext Cache, Session & Lock Driver Contract

Status: **Normative implementation baseline**  
Roadmap step: **03.04 — Cache/session/lock sürücüleri**

## Driver strategy

Forwext exposes shared contracts rather than allowing subsystems to talk directly to files, database tables or Redis. The minimum cPanel profile defaults to file-backed drivers; database and Redis-backed drivers can be selected when the deployment profile supports them.

Implemented cache drivers:

- `FileCacheStore` — single-host/shared-host compatible local cache;
- `DatabaseCacheStore` — MySQL/MariaDB cache for multi-process/shared state;
- `RedisCacheStore` — optional Redis cache through the `RedisClient` abstraction.

Implemented session drivers:

- `FileSessionStore`;
- `DatabaseSessionStore`;
- `RedisSessionStore`.

Implemented lock drivers:

- `FileLockManager` — local OS advisory lock (`flock`), appropriate only when contenders share the same filesystem/host lock semantics;
- `DatabaseLockManager` — database lease with unique token and expiry, suitable for nodes sharing the same database;
- `RedisLockManager` — Redis NX/PX lease with random ownership token and atomic compare-and-delete release.

`PhpRedisClient` is the optional adapter for `ext-redis`. Redis is not a mandatory production extension; selecting a Redis driver without the extension/client capability must fail rather than silently falling back to unsafe partial behavior.

## Cache semantics

`CacheStore` stores opaque strings. Higher layers are responsible for deliberate encoding; PHP object serialization is not introduced as an implicit cache format.

Entries support optional TTL and tags. Expired entries behave as misses. Tag invalidation removes every currently indexed entry carrying the tag.

Keys/tags are validated and filesystem/database/Redis storage keys are hashed where useful so caller-controlled keys never become paths, SQL identifiers or unbounded backend keys.

### Stampede protection

`CacheRememberService` follows double-checked locking:

1. read cache;
2. on miss acquire a regeneration lock derived from the cache key;
3. read cache again after lock acquisition;
4. only the lock owner runs the producer;
5. store the produced value;
6. release the lock in `finally`.

Failure to acquire the regeneration lock fails explicitly rather than running an uncontrolled duplicate producer. Later feature-specific code may implement stale-while-revalidate semantics separately when appropriate.

## Session semantics

Session stores operate on opaque payload strings and absolute UTC expiry. They do not define authentication/session-cookie policy; authentication work later controls session-id generation, rotation, fixation defense and account/device semantics.

- File and DB stores implement explicit expiry checks and bounded garbage collection.
- Redis uses native TTL; `collectGarbage()` is therefore a validated no-op.
- Session identifiers are validated and hashed for backend lookup/path names.
- File session data is stored under protected runtime storage with atomic replacement and restrictive permissions.

Session payloads may contain sensitive state and must never be placed in the public web root or logs.

## Lock semantics

A lock handle represents ownership, not merely existence of a key.

### File

`flock` ownership is tied to the open file handle and released by the OS when the descriptor/process closes. The TTL parameter cannot turn `flock` into a distributed lease; callers must not use file locks to coordinate independent hosts.

### Database

Database locks are rows with a random ownership token and UTC expiry. Acquisition uses one transaction, a conditional upsert that only replaces an expired lease, followed by `FOR UPDATE` token verification. Release deletes only when the stored ownership token matches.

Database connectivity/query failures are propagated and never misreported as ordinary lock contention.

### Redis

Redis acquisition requires SET-if-absent with PX TTL. Release uses an atomic compare-and-delete operation (Lua in the `ext-redis` adapter), preventing a stale owner from deleting a newer owner's lease after expiry/reacquisition.

## Database schema

Core migration `20260914203000_infrastructure_drivers` creates:

- `forwext_cache`;
- `forwext_cache_tags`;
- `forwext_sessions`;
- `forwext_locks`.

The migration is versioned/idempotent and non-transactional because MySQL DDL transaction guarantees are not assumed. Its verification checks that all four tables exist.

Cache-tag rows use a foreign key with cascade deletion so stale relational tag rows cannot outlive a deleted cache entry.

## Redis tag indexes

Redis cache tag indexes are sets. They intentionally do not inherit the TTL of one arbitrary member because entries sharing a tag may have different TTLs. Overwrite/delete/invalidation removes known members; stale members from natural Redis expiry are harmless and are removed when the tag is invalidated.

## File safety

File cache/session drivers:

- use hashed filenames;
- reject symbolic-link destinations/entries;
- stage writes in the same directory;
- atomically rename staged files;
- request restrictive `0600` file permissions;
- keep data below protected `storage/` paths by default.

## Configuration defaults

Default cPanel-safe configuration is:

- cache: `file`, `storage/cache/data`, default TTL 300s;
- session: `file`, `storage/sessions`, TTL 7200s;
- lock: `file`, `storage/locks`, default TTL 30s.

Selecting DB/Redis is an explicit deployment decision. Credentials remain in the secret system, not ordinary configuration.

## Security / permission boundary

Cache/session/lock drivers are infrastructure, not authorization mechanisms.

- Cache hits must never bypass backend permission checks for user-specific protected objects unless the cached artifact is itself correctly permission-scoped.
- Session storage does not prove the session is authorized/authenticated; authentication services interpret it.
- Lock ownership does not grant permission to perform the protected business action.
- Redis/database errors fail visibly; drivers do not silently downgrade to a weaker consistency model.

## Acceptance status

03.04 is complete when file/DB/Redis cache and session implementations, local/distributed lock implementations, cache invalidation/tagging, regeneration stampede protection, concrete DB schema migration and driver behavior tests exist as real code.
