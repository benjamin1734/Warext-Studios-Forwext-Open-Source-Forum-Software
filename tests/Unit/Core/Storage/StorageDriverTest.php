<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Storage;

use Forwext\Core\Storage\LocalStorageDriver;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\S3\S3CompatibleClient;
use Forwext\Core\Storage\S3\S3ObjectMetadata;
use Forwext\Core\Storage\S3\S3StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StorageDriverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-storage-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testStoragePathRejectsTraversalAndAbsolutePaths(): void
    {
        foreach (['../secret', '/etc/passwd', 'a/../b', 'a\\b'] as $invalid) {
            try {
                StoragePath::fromString($invalid);
                self::fail('Expected invalid storage path: ' . $invalid);
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('avatars/user-1/image.bin', StoragePath::fromString('avatars/user-1/image.bin')->value());
    }

    public function testLocalStorageKeepsPublicAndPrivateNamespacesSeparateAndBinarySafe(): void
    {
        $driver = new LocalStorageDriver($this->directory . '/private', $this->directory . '/public', 'https://cdn.example.test/files');
        $path = StoragePath::fromString('avatars/u1/blob.bin');
        $binary = "\x00\xFF\x10binary";

        $object = $driver->put($path, $binary, StorageVisibility::Private, 'application/octet-stream');
        self::assertSame(strlen($binary), $object->size);
        self::assertSame(hash('sha256', $binary), $object->sha256);
        self::assertSame($binary, $driver->read($path, StorageVisibility::Private));
        self::assertFalse($driver->exists($path, StorageVisibility::Public));
        self::assertNull($driver->publicUrl($path));

        $driver->put($path, 'public', StorageVisibility::Public, 'text/plain');
        self::assertSame('https://cdn.example.test/files/avatars/u1/blob.bin', $driver->publicUrl($path));
    }

    public function testLocalStreamWriteAndReadDoNotRequireStringApi(): void
    {
        $driver = new LocalStorageDriver($this->directory . '/private', $this->directory . '/public');
        $path = StoragePath::fromString('media/chunk.bin');
        $stream = ReadableStream::fromString(str_repeat('x', 2 * 1024 * 1024));
        try {
            $stored = $driver->putStream($path, $stream);
            self::assertSame(2 * 1024 * 1024, $stored->size);
        } finally {
            $stream->close();
        }
        $read = $driver->readStream($path);
        try {
            self::assertSame(2 * 1024 * 1024, strlen($read->contents()));
        } finally {
            $read->close();
        }
    }

    public function testS3AdapterPreservesVisibilityAndPrivateSigningBoundary(): void
    {
        $client = new FakeS3Client();
        $driver = new S3StorageDriver($client, 'forwext-test-bucket', 'tenant-a');
        $public = StoragePath::fromString('public/logo.png');
        $private = StoragePath::fromString('private/report.bin');

        $driver->put($public, 'logo', StorageVisibility::Public, 'image/png');
        $driver->put($private, 'secret', StorageVisibility::Private);

        self::assertSame('https://objects.example/forwext-test-bucket/tenant-a/public/logo.png', $driver->publicUrl($public));
        self::assertNull($driver->publicUrl($private));
        self::assertStringContainsString('signed=300', $driver->temporaryPrivateUrl($private, 300));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

final class FakeS3Client implements S3CompatibleClient
{
    /** @var array<string, array{body:string, metadata:S3ObjectMetadata}> */
    private array $objects = [];

    public function putObject(string $bucket, string $key, ReadableStream $stream, StorageVisibility $visibility, ?string $contentType = null): S3ObjectMetadata
    {
        $contents = $stream->contents();
        $metadata = new S3ObjectMetadata(strlen($contents), hash('sha256', $contents), $visibility, $contentType);
        $this->objects[$bucket . '/' . $key] = ['body' => $contents, 'metadata' => $metadata];
        return $metadata;
    }

    public function getObjectStream(string $bucket, string $key): ReadableStream
    {
        return ReadableStream::fromString($this->objects[$bucket . '/' . $key]['body']);
    }

    public function headObject(string $bucket, string $key): ?S3ObjectMetadata
    {
        return $this->objects[$bucket . '/' . $key]['metadata'] ?? null;
    }

    public function deleteObject(string $bucket, string $key): bool
    {
        $id = $bucket . '/' . $key;
        $exists = isset($this->objects[$id]);
        unset($this->objects[$id]);
        return $exists;
    }

    public function publicObjectUrl(string $bucket, string $key): string
    {
        return 'https://objects.example/' . rawurlencode($bucket) . '/' . $key;
    }

    public function temporaryPrivateUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        return 'https://objects.example/' . rawurlencode($bucket) . '/' . $key . '?signed=' . $ttlSeconds;
    }
}
