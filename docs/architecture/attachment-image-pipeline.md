# Attachment and Image Pipeline (07.01)

## Scope

07.01 implements the persistent forum attachment pipeline required by the roadmap: temporary uploads, byte-signature/MIME validation, quotas, thumbnails, image metadata privacy, secure private downloads and bounded orphan cleanup.

All forum attachments remain in `StorageVisibility::Private`. Client filenames are display metadata only and never become storage path segments. Authorized bytes are served through `AttachmentService::download()` and the web download response; no public attachment storage URL is exposed.

## Upload and quota boundary

`AttachmentService::stage()` is actor-bound through the shared permission engine. It requires `forum.view` and `forum.attachment.upload`, validates bytes with `AttachmentInspector`, strips supported image metadata, optionally generates a thumbnail, writes private temporary objects and finally creates the metadata row. Storage is deleted again if metadata persistence fails.

Default policy is configurable through `attachments.*`: 25 MiB per file, 100 MiB and 20 objects in temporary state, 512 MiB total stored bytes per user, 40 million image pixels, 24-hour temporary TTL and 480×480 thumbnails.

The service performs a fast quota preflight. `DatabaseAttachmentRepository::createTemporary()` then locks the owning `forwext_users` row with `FOR UPDATE`, recomputes usage and rechecks the limits before insert. Concurrent staging requests therefore cannot bypass quota by racing each other.

## Byte signature and image safety

Browser-provided MIME is never authoritative. Accepted payloads are JPEG, PNG, GIF, WebP, PDF, ZIP and valid UTF-8 plain text without unsafe binary controls. Images must additionally decode through `getimagesizefromstring()` and the decoded MIME must match the detected signature. Pixel count is bounded to reduce decompression-bomb exposure. Unknown or malformed binaries fail closed with an attachment validation error.

## Metadata privacy

Privacy stripping happens before SHA-256 and storage identity are calculated:

- JPEG APP1 (EXIF/XMP), APP13 (IPTC/Photoshop) and COM segments are removed.
- PNG `eXIf`, `tEXt`, `zTXt` and `iTXt` chunks are removed.
- WebP `EXIF` and `XMP ` chunks are removed.

The implementation is pure PHP and does not require the optional EXIF extension. `metadata_stripped` records that the submitted image changed during sanitization.

## Thumbnails

`GdAttachmentThumbnailGenerator` is capability-based. When GD is available it resamples the already inspected/sanitized image into the configured bounding box; JPEG stays JPEG and other supported image input becomes alpha-safe PNG. When GD is unavailable the original upload still works and `thumbnail_path` remains `NULL`. GD is not a minimum cPanel dependency.

## Finalization

Temporary attachments are owner-bound and expire. Finalization requires an active target post/thread in the same forum. Cross-owner management additionally requires `forum.attachment.manage_any`; attaching to another user's post requires the existing `forum.post.edit_any` permission.

Before a staged object is promoted, its byte length and SHA-256 are revalidated. Final storage paths are non-client-derived and contain a random per-finalization nonce. The database transition is guarded by attachment id, owner id and `state = temporary`, so only one transition can win while a losing concurrent request cannot delete a winning request's destination.

## Secure downloads

Downloads require attached state, an active post/thread, matching forum binding, `forum.view` and `forum.attachment.download`. Deleted or non-visible post attachments are available only to the attachment owner or an actor with `forum.post.moderate`.

Original downloads re-check persisted byte length and SHA-256. The HTTP response uses server-derived MIME, explicit length, safe RFC5987 content disposition, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`. Thumbnail filenames use the thumbnail's real generated extension rather than the original file extension.

## Orphan cleanup

`AttachmentCleanupService` is independent from user permission/session state so maintenance execution never invents a system user. It scans only expired temporary rows in bounded batches, removes thumbnail/original private storage first, then removes the metadata row. A storage exception keeps the metadata row for retry.

`AttachmentMaintenanceTasks` registers hourly `forum.attachments.cleanup` work on the existing maintenance queue, and `AttachmentCleanupJobHandler` validates the bounded cleanup payload before invoking the service. The platform's scheduler currently dispatches queue jobs; the attachment job has an explicit job type/handler contract rather than embedding cleanup in a web request.

## Persistence and permissions

Migration `20260916001000_attachment_pipeline` creates `forwext_attachments` with owner/forum/post references, media metadata, private storage paths, image dimensions and temporary/final lifecycle timestamps. Referential deletion is `RESTRICT` so external storage must be deliberately cleaned before owner/forum/post records disappear.

Permissions:

- `forum.attachment.upload`
- `forum.attachment.download`
- `forum.attachment.manage_any`

All five starter profiles receive explicit allow/deny rules, preventing stale elevated grants after profile downgrades.

## Web surface and deployment

The web pipeline exposes authenticated, CSRF-protected staging/finalization and an authenticated private download route. PHP upload provenance is checked with `is_uploaded_file()` before staging bytes are accepted; the server then performs its own signature validation.

The minimum deployment remains compatible with normal cPanel PHP/MySQL hosting. The feature requires no Node, Redis, Supervisor, SSH or mandatory worker daemon; GD remains optional.
