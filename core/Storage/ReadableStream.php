<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

final class ReadableStream
{
    /** @var resource|null */
    private $resource;

    /** @param resource $resource */
    private function __construct($resource)
    {
        if (!is_resource($resource)) {
            throw new StorageException('Storage stream requires a valid resource.');
        }
        $this->resource = $resource;
    }

    /** @param resource $resource */
    public static function fromResource($resource): self
    {
        return new self($resource);
    }

    public static function fromString(string $contents): self
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new StorageException('Unable to allocate temporary storage stream.');
        }
        if (fwrite($resource, $contents) !== strlen($contents) || !rewind($resource)) {
            fclose($resource);
            throw new StorageException('Unable to initialize temporary storage stream.');
        }
        return new self($resource);
    }

    /** @return resource */
    public function resource()
    {
        if (!is_resource($this->resource)) {
            throw new StorageException('Storage stream is closed.');
        }
        return $this->resource;
    }

    public function rewind(): void
    {
        if (!rewind($this->resource())) {
            throw new StorageException('Unable to rewind storage stream.');
        }
    }

    public function contents(): string
    {
        $resource = $this->resource();
        $position = ftell($resource);
        if ($position === false || !rewind($resource)) {
            throw new StorageException('Unable to read storage stream.');
        }
        $contents = stream_get_contents($resource);
        if ($contents === false) {
            throw new StorageException('Unable to read storage stream.');
        }
        if (fseek($resource, $position) !== 0) {
            throw new StorageException('Unable to restore storage stream position.');
        }
        return $contents;
    }

    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->resource = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
