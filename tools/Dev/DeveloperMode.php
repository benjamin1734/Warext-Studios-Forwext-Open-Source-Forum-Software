<?php

declare(strict_types=1);

namespace Forwext\Tools\Dev;

use JsonException;
use RuntimeException;

final readonly class DeveloperMode
{
    private string $root;

    public function __construct(string $projectRoot)
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root) || !is_file($root . '/VERSION')) {
            throw new RuntimeException('Forwext project root is invalid.');
        }
        $this->root = $root;
    }

    public function enable(): void
    {
        $directory = $this->root . '/storage/dev';
        if (is_link($directory)) {
            throw new RuntimeException('Developer mode directory may not be a symlink.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create developer mode directory.');
        }
        try {
            $json = json_encode(['schema'=>1,'enabled'=>true], JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode developer mode marker.', previous:$exception);
        }
        $this->atomicWrite($this->marker(), $json);
    }

    public function disable(): void
    {
        $marker = $this->marker();
        if (is_link($marker)) {
            throw new RuntimeException('Developer mode marker may not be a symlink.');
        }
        if (is_file($marker) && !unlink($marker)) {
            throw new RuntimeException('Unable to disable developer mode.');
        }
    }

    public function isEnabled(): bool
    {
        $marker = $this->marker();
        if (!is_file($marker) || is_link($marker)) {
            return false;
        }
        $raw = file_get_contents($marker);
        if (!is_string($raw)) {
            return false;
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($data) && ($data['schema'] ?? null) === 1 && ($data['enabled'] ?? null) === true;
    }

    private function marker(): string
    {
        return $this->root . '/storage/dev/developer-mode.json';
    }

    private function atomicWrite(string $path, string $content): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Developer mode marker may not be a symlink.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to write developer mode marker.');
            }
            if (!chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish developer mode marker.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
