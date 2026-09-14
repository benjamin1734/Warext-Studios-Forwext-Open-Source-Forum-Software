<?php

declare(strict_types=1);

namespace Forwext\Core\Storage\S3;

use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageVisibility;

interface S3CompatibleClient
{
    public function putObject(
        string $bucket,
        string $key,
        ReadableStream $stream,
        StorageVisibility $visibility,
        ?string $contentType = null,
    ): S3ObjectMetadata;

    public function getObjectStream(string $bucket, string $key): ReadableStream;

    public function headObject(string $bucket, string $key): ?S3ObjectMetadata;

    public function deleteObject(string $bucket, string $key): bool;

    public function publicObjectUrl(string $bucket, string $key): string;

    public function temporaryPrivateUrl(string $bucket, string $key, int $ttlSeconds): string;
}
