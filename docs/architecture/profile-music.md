# Profile music architecture

Roadmap step **04.07** adds permission-aware profile music without introducing a second role engine before the shared role/group/permission work in step 05.x.

## Permission boundary

`ProfileMusicPermissionResolver` is the single authorization integration boundary for profile music capabilities:

- `profile.music.use`
- `profile.music.upload`
- `profile.music.external`
- `profile.music.autoplay`
- `profile.music.moderate`

The cPanel-safe baseline uses `BaselineProfileMusicPermissionResolver` with conservative configuration defaults. Step 05.x can replace this resolver with the shared role/group permission engine without changing the music domain or HTTP handlers. Owner edits still pass through the existing profile access policy; music permission does not override profile ownership by itself.

## Persistence and moderation

`forwext_profile_music` stores one current settings row per user. Upload/external source selection, title, visibility, volume, mute, autoplay, loop and moderation state are persisted independently from the core profile row.

Moderation changes also append to `forwext_profile_music_moderation_history`. Block/unblock events keep actor, UTC timestamp and a bounded reason code. Playback is denied while the current source is blocked. The history table is audit-oriented and is not rewritten when the current row changes.

## Uploaded audio

Uploaded music is private storage data and never receives a direct public-storage URL. The accepted baseline formats are MP3, Ogg, WAV and M4A. Detection uses bounded binary signatures rather than trusting a submitted filename or request MIME type.

Default upload size is 20 MiB and is configurable with a hard service-side upper bound. Stored names are content-addressed with SHA-256 beneath `profiles/{user-id}/music/`. Source replacement writes the new object before committing the new database reference; a failed database save removes the staged object best-effort, and successfully replaced old uploads are removed best-effort afterward.

The native `/members/{username}/music` endpoint re-checks account status, profile/music visibility, runtime permissions and moderation before reading private storage. It supports one HTTP byte range, returns `206 Partial Content` for a satisfiable range and `416 Range Not Satisfiable` for invalid/unsatisfiable ranges. Responses use `private, no-store`, `Accept-Ranges: bytes` and `X-Content-Type-Options: nosniff`.

## External sources

External music is disabled by default. Enabling the capability still requires an explicit exact-host allowlist. URLs must be credential-free HTTPS URLs on port 443, without fragments. IP literals, localhost and wildcard/subdomain inheritance are rejected.

The normalized allowlist is also used to build the web `Content-Security-Policy` `media-src` directive. Removing a host from the allowlist invalidates previously stored URLs at render time; the player fails closed and is omitted rather than causing a profile error or bypassing the current policy. External audio is loaded directly by the browser and is not proxied through Forwext.

## Player and autoplay

The native profile page renders a standard `<audio controls preload="metadata">` element. Persisted volume, mute and autoplay preference are exposed through bounded `data-*` attributes and initialized by the first-party `/assets/profile-music.js` script.

The HTML never emits the `autoplay` attribute. When autoplay is requested and permitted, JavaScript may attempt `play()` only on non-coarse-pointer clients and starts the attempt muted. It never automatically unmutes. Rejected browser autoplay promises are handled without breaking the profile. Coarse-pointer/mobile clients require an explicit user gesture.

The player is responsive and uses the browser-native audio control surface so keyboard, screen-reader and mobile media behavior remain available without a custom inaccessible control layer.

## Visibility and fail-closed behavior

Music visibility uses the existing `public`, `members` and `private` profile visibility model. Every playback request is evaluated against the current authenticated viewer. Runtime loss of `use`, `upload`, `external` or `autoplay` capability immediately affects subsequent reads/renders; persisted historical settings do not grant access by themselves.

Unknown users, inactive accounts, hidden music, blocked music and inaccessible uploaded sources use the same not-found behavior at the media endpoint. Invalidated external hosts simply remove the player from an otherwise valid profile.

## cPanel/runtime impact

The baseline requires no Node.js process, Redis service, worker daemon, ffmpeg or media-transcoding extension. Local private storage, PDO MySQL and the existing native PHP web surface are sufficient. Advanced storage remains possible through the existing storage abstraction when a deployment-specific composition is added.
