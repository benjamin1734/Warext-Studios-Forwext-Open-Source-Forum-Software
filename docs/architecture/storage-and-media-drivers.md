# Forwext Storage & Media Driver Contract

Status: **Normative implementation baseline**  
Roadmap step: **03.05 — Storage ve medya sürücüleri**

## Storage abstraction

Forwext stores files through `StorageDriver`, not direct feature-level filesystem/S3 calls. The same internal storage path and visibility rules apply to local and S3-compatible backends.

`StoragePath` is an application-generated internal key, not a client filename. It rejects absolute paths, `.`/`..`, backslashes, control characters, empty segments and unsupported segment characters before any backend operation.

## Visibility

Every stored object belongs to exactly one visibility scope:

- `Private`: not directly published; access must pass an authorized application route or an explicitly generated temporary signed object URL.
- `Public`: may have a stable public URL when the configured backend supports it.

A path existing in public storage does not imply the same path exists in private storage and vice versa. Local storage uses physically separate roots for these namespaces.

Visibility is storage policy, not forum authorization. Higher-level permission services still decide who may create/read/delete a private attachment, avatar, marketplace asset or other media.

## Binary and streaming behavior

Object contents are opaque bytes. Drivers must preserve NUL/non-UTF-8 data exactly.

`ReadableStream` provides an explicit resource wrapper so large media can be transferred without forcing the caller through a full in-memory string API. `StorageDriver` supports both string convenience operations and stream operations.

Local writes copy the source stream in bounded chunks, compute SHA-256 while writing and atomically rename the staged object. S3-compatible adapters expose stream upload/download contracts so a concrete provider SDK can stream rather than buffer the complete object.

## Local driver

`LocalStorageDriver` uses separate configured private/public roots.

Security behavior:

- storage paths use strict safe internal segments;
- existing symbolic-link roots/ancestors/destinations are rejected;
- parent directories are created only under the configured root;
- writes use same-directory staging plus atomic rename;
- private objects request `0600`, public objects `0644`;
- SHA-256 and byte length are computed from stored content;
- private objects never receive a public URL from the driver.

Default paths:

- private: `storage/files/private`;
- public: `public/storage`.

The public base URL remains unset until canonical deployment configuration supplies the real public origin/path.

## S3-compatible contract

`S3StorageDriver` depends on `S3CompatibleClient`; the core is not tied to AWS SDK, MinIO SDK or one vendor-specific package.

A concrete S3 client must implement:

- streaming/object upload with visibility and optional content type;
- streaming object read;
- object metadata/head;
- delete;
- public object URL;
- bounded temporary private URL signing.

The adapter preserves the configured bucket/prefix and refuses a visibility mismatch reported by the backend after upload.

S3-compatible credentials are referenced through the secret system (`storage.s3.access_key` / `storage.s3.secret_key`) rather than embedded in source/generated plain configuration.

## Metadata

`StoredObject` carries:

- internal path;
- visibility;
- byte size;
- SHA-256 content digest;
- optional validated content type.

Content type is metadata only. It does not prove uploaded file safety or actual MIME signature. Full upload/media validation, image decoding/transcoding, quota and orphan cleanup are handled by roadmap step 07.01.

Local filesystem metadata cannot reliably preserve arbitrary object content-type metadata without a dedicated metadata database; therefore local `metadata()` may return `null` content type after a later read even when the immediate `put()` result contained it. Security decisions must never depend only on this optional field.

## Public/private URL rules

- `publicUrl()` returns a URL only for an existing public object.
- Private local objects have no direct URL.
- S3 private URLs are generated only through `temporaryPrivateUrl()` with a TTL between 1 and 86400 seconds.
- URL generation is separate from permission checks; calling code must authorize before issuing private URLs.

## Failure behavior

Backend failures throw `StorageException` (or provider adapter exceptions translated to it by concrete clients). A driver must not silently move a private object into public storage or fall back from S3 to local storage after a partial write.

No database migration is required for 03.05; the storage abstraction itself does not add persisted relational state.

## Acceptance status

03.05 is complete when safe storage paths, local public/private object storage, binary/stream support, object metadata, public/private URL separation, and S3-compatible adapter contracts are implemented with tests for traversal rejection, binary preservation, namespace separation, streaming and S3 visibility/signing semantics.
