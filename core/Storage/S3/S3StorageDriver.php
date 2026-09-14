<?php

declare(strict_types=1);

namespace Forwext\Core\Storage\S3;

use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoredObject;
use Forwext\Core\Storage\StorageException;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;

final readonly class S3StorageDriver implements StorageDriver
{
    public function __construct(
        private S3CompatibleClient $client,
        private string $bucket,
        private string $prefix = '',
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
            throw new StorageException('S3 bucket name is invalid.');
        }
        if ($prefix !== '') {
            StoragePath::fromString(trim($prefix, '/'));
        }
    }

    public function put(
        StoragePath $path,
        string $contents,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        $stream = ReadableStream::fromString($contents);
        try {
            return $this->putStream($path, $stream, $visibility, $contentType);
        } finally {
            $stream->close();
        }
    }

    public function putStream(
        StoragePath $path,
        ReadableStream $stream,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        StoredObject::validateContentType($contentType);
        $metadata = $this->client->putObject($this->bucket, $this->key($path), $stream, $visibility, $contentType);
        if ($metadata->visibility !== $visibility) {
            throw new StorageException('S3 client returned a visibility mismatch after upload.');
        }
        return new StoredObject($path, $metadata->visibility, $metadata->size, $metadata->sha256, $metadata->contentType);
    }

    public function read(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): string
    {
        $stream = $this->readStream($path, $visibility);
        try {
            return $stream->contents();
        } finally {
            $stream->close();
        }
    }

    public function readStream(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ReadableStream
    {
        if ($this->metadata($path, $visibility) === null) {
            throw new StorageException('S3 object does not exist in the requested visibility scope.');
        }
        return $this->client->getObjectStream($this->bucket, $this->key($path));
    }

    public function metadata(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ?StoredObject
    {
        $metadata = $this->client->headObject($this->bucket, $this->key($path));
        if ($metadata === null || $metadata->visibility !== $visibility) {
            return null;
        }
        return new StoredObject($path, $metadata->visibility, $metadata->size, $metadata->sha256, $metadata->contentType);
    }

    public function exists(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        return $this->metadata($path, $visibility) !== null;
    }

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        return $this->metadata($path, $visibility) !== null
            && $this->client->deleteObject($this->bucket, $this->key($path));
    }

    public function publicUrl(StoragePath $path): ?string
    {
        return $this->exists($path, StorageVisibility::Public)
            ? $this->client->publicObjectUrl($this->bucket, $this->key($path))
            : null;
    }

    public function temporaryPrivateUrl(StoragePath $path, int $ttlSeconds = 300): string
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new StorageException('Temporary private URL TTL must be between 1 and 86400 seconds.');
        }
        if (!$this->exists($path, StorageVisibility::Private)) {
            throw new StorageException('Private S3 object does not exist.');
        }
        return $this->client->temporaryPrivateUrl($this->bucket, $this->key($path), $ttlSeconds);
    }

    private function key(StoragePath $path): string
    {
        $prefix = trim($this->prefix, '/');
        return $prefix === '' ? $path->value() : $prefix . '/' . $path->value();
    }
}
