<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

final readonly class LocalStorageDriver implements StorageDriver
{
    public function __construct(
        private string $privateRoot,
        private string $publicRoot,
        private ?string $publicBaseUrl = null,
    ) {
        if ($privateRoot === '' || $publicRoot === '') {
            throw new StorageException('Local storage roots cannot be empty.');
        }
        if ($publicBaseUrl !== null && filter_var($publicBaseUrl, FILTER_VALIDATE_URL) === false) {
            throw new StorageException('Local public storage base URL is invalid.');
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
        $root = $this->root($visibility);
        $target = $this->absolutePath($root, $path, createParents: true);
        $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $destination = @fopen($temporary, 'x+b');
        if ($destination === false) {
            throw new StorageException('Unable to create staged local storage object.');
        }
        $destinationOpen = true;

        try {
            $stream->rewind();
            $source = $stream->resource();
            $hash = hash_init('sha256');
            $size = 0;

            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new StorageException('Unable to read source storage stream.');
                }
                if ($chunk === '') {
                    continue;
                }
                $written = fwrite($destination, $chunk);
                if ($written !== strlen($chunk)) {
                    throw new StorageException('Unable to write staged local storage object.');
                }
                hash_update($hash, $chunk);
                $size += $written;
            }

            if (!fflush($destination)) {
                throw new StorageException('Unable to flush staged local storage object.');
            }
            fclose($destination);
            $destinationOpen = false;

            $mode = $visibility === StorageVisibility::Public ? 0644 : 0600;
            if (!@chmod($temporary, $mode)) {
                throw new StorageException('Unable to restrict local storage object permissions.');
            }
            if (is_link($target)) {
                throw new StorageException('Storage destination may not be a symbolic link.');
            }
            if (!@rename($temporary, $target)) {
                throw new StorageException('Unable to atomically replace local storage object.');
            }

            return new StoredObject($path, $visibility, $size, hash_final($hash), $contentType);
        } finally {
            if ($destinationOpen && is_resource($destination)) {
                fclose($destination);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
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
        $target = $this->absolutePath($this->root($visibility), $path);
        if (!is_file($target) || is_link($target)) {
            throw new StorageException('Stored object does not exist or is not a regular file.');
        }
        $resource = @fopen($target, 'rb');
        if ($resource === false) {
            throw new StorageException('Unable to open local storage object.');
        }
        return ReadableStream::fromResource($resource);
    }

    public function metadata(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ?StoredObject
    {
        $target = $this->absolutePath($this->root($visibility), $path);
        if (!is_file($target) || is_link($target)) {
            return null;
        }
        $size = filesize($target);
        $checksum = hash_file('sha256', $target);
        if (!is_int($size) || !is_string($checksum)) {
            throw new StorageException('Unable to inspect local storage object.');
        }
        return new StoredObject($path, $visibility, $size, $checksum);
    }

    public function exists(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        $target = $this->absolutePath($this->root($visibility), $path);
        return is_file($target) && !is_link($target);
    }

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        $target = $this->absolutePath($this->root($visibility), $path);
        if (is_link($target)) {
            throw new StorageException('Storage object may not be a symbolic link.');
        }
        return is_file($target) ? @unlink($target) : false;
    }

    public function publicUrl(StoragePath $path): ?string
    {
        if ($this->publicBaseUrl === null || !$this->exists($path, StorageVisibility::Public)) {
            return null;
        }
        $encoded = implode('/', array_map('rawurlencode', $path->segments()));
        return rtrim($this->publicBaseUrl, '/') . '/' . $encoded;
    }

    private function root(StorageVisibility $visibility): string
    {
        return $visibility === StorageVisibility::Public ? $this->publicRoot : $this->privateRoot;
    }

    private function absolutePath(string $root, StoragePath $path, bool $createParents = false): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '' || is_link($root)) {
            throw new StorageException('Storage root is invalid or symbolic.');
        }
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new StorageException('Unable to create storage root.');
        }

        $current = $root;
        $segments = $path->segments();
        $lastIndex = array_key_last($segments);
        foreach ($segments as $index => $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if ($index === $lastIndex) {
                break;
            }
            if (is_link($current)) {
                throw new StorageException('Storage path traverses a symbolic link.');
            }
            if ($createParents && !is_dir($current) && !mkdir($current, 0700) && !is_dir($current)) {
                throw new StorageException('Unable to create storage directory.');
            }
            if (file_exists($current) && !is_dir($current)) {
                throw new StorageException('Storage path parent is not a directory.');
            }
        }

        return $current;
    }
}
