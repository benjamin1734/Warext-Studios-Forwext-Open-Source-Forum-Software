<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use JsonException;

final readonly class FileInstalledVersionStore implements InstalledVersionStore
{
    public function __construct(private string $path)
    {
    }

    public function current(): ?SemanticVersion
    {
        if (!file_exists($this->path)) {
            return null;
        }
        if (is_link($this->path)) {
            throw new MigrationException('Installed-version file may not be a symbolic link.');
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new MigrationException('Unable to read installed-version state.');
        }

        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MigrationException('Installed-version state is invalid.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
            throw new MigrationException('Installed-version state has an invalid shape.');
        }

        return SemanticVersion::parse($decoded['version']);
    }

    public function write(SemanticVersion $version): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new MigrationException('Unable to create installed-version directory.');
        }
        if (is_link($this->path)) {
            throw new MigrationException('Installed-version file may not be a symbolic link.');
        }

        $payload = json_encode(
            ['version' => $version->value()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;
        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new MigrationException('Unable to stage installed-version state.');
            }
            if (!@chmod($temporary, 0600)) {
                throw new MigrationException('Unable to restrict installed-version state permissions.');
            }
            if (!@rename($temporary, $this->path)) {
                throw new MigrationException('Unable to atomically replace installed-version state.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
