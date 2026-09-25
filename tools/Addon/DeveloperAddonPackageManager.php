<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use FilesystemIterator;
use Forwext\Core\Addon\AddonManifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class DeveloperAddonPackageManager
{
    private string $root;

    public function __construct(string $projectRoot)
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_file($root . '/VERSION')) {
            throw new RuntimeException('Forwext project root is invalid.');
        }
        $this->root = $root;
    }

    public function install(string $sourceDirectory): string
    {
        [$source, $manifest] = $this->source($sourceDirectory);
        $target = $this->target($manifest);
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Add-on is already present in the development workspace.');
        }
        $this->publish($source, $target, null);
        return $manifest->id->value();
    }

    public function upgrade(string $sourceDirectory): string
    {
        [$source, $manifest] = $this->source($sourceDirectory);
        $target = $this->target($manifest);
        if (!is_dir($target) || is_link($target) || !is_file($target . '/addon.json')) {
            throw new RuntimeException('Installed development add-on was not found.');
        }
        $current = AddonManifest::fromJson((string) file_get_contents($target . '/addon.json'));
        if (!$manifest->version->isGreaterThan($current->version)) {
            throw new RuntimeException('Development add-on upgrade version must be newer.');
        }
        $this->publish($source, $target, $target);
        return $manifest->id->value();
    }

    /** @return array{string,AddonManifest} */
    private function source(string $sourceDirectory): array
    {
        $source = realpath($sourceDirectory);
        if (!is_string($source) || !is_dir($source) || is_link($source)) {
            throw new RuntimeException('Development add-on source directory is invalid.');
        }
        $manifestPath = $source . '/addon.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('Development add-on source requires addon.json.');
        }
        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read development add-on manifest.');
        }
        $manifest = AddonManifest::fromJson($raw);

        $entries = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            ++$entries;
            if ($entries > 5000 || $entry->isLink()) {
                throw new RuntimeException('Development add-on source contains unsupported entries.');
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
                if ($entry->getSize() > 67108864 || $bytes > 268435456) {
                    throw new RuntimeException('Development add-on source exceeds package limits.');
                }
            } elseif (!$entry->isDir()) {
                throw new RuntimeException('Development add-on source contains unsupported filesystem entries.');
            }
        }
        return [$source, $manifest];
    }

    private function target(AddonManifest $manifest): string
    {
        return $this->root . '/addons/' . $manifest->id->vendor() . '/' . $manifest->id->name();
    }

    private function publish(string $source, string $target, ?string $replace): void
    {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create development add-on target parent.');
        }
        $temporary = $parent . '/.' . basename($target) . '.stage-' . bin2hex(random_bytes(8));
        $backup = $parent . '/.' . basename($target) . '.backup-' . bin2hex(random_bytes(8));

        try {
            $this->copyTree($source, $temporary);
            if ($replace !== null && !rename($replace, $backup)) {
                throw new RuntimeException('Unable to stage existing development add-on for replacement.');
            }
            if (!rename($temporary, $target)) {
                if (is_dir($backup)) {
                    @rename($backup, $target);
                }
                throw new RuntimeException('Unable to publish development add-on.');
            }
            if (is_dir($backup)) {
                $this->removeTree($backup);
            }
        } catch (\Throwable $exception) {
            $this->removeTree($temporary);
            if (!is_dir($target) && is_dir($backup)) {
                @rename($backup, $target);
            }
            throw $exception;
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (!mkdir($target, 0755, true) && !is_dir($target)) {
            throw new RuntimeException('Unable to create staged add-on directory.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
            if ($relative === '.git'
                || str_starts_with($relative, '.git/')
                || $relative === '.forwext'
                || str_starts_with($relative, '.forwext/')
            ) {
                continue;
            }
            $destination = $target . '/' . $relative;
            if ($entry->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new RuntimeException('Unable to create staged add-on directory.');
                }
                continue;
            }
            $content = file_get_contents($entry->getPathname());
            if (!is_string($content) || file_put_contents($destination, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Unable to copy staged add-on file.');
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
