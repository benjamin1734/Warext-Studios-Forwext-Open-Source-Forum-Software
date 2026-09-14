<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

interface StorageDriver
{
    public function put(
        StoragePath $path,
        string $contents,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject;

    public function putStream(
        StoragePath $path,
        ReadableStream $stream,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject;

    public function read(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): string;

    public function readStream(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): ReadableStream;

    public function metadata(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ?StoredObject;

    public function exists(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool;

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool;

    public function publicUrl(StoragePath $path): ?string;
}
