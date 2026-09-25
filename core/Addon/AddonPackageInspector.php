<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class AddonPackageInspector
{
    private string $root;

    public function __construct(
        string $addonsRoot,
        private int $maxEntries = 5000,
        private int $maxFileBytes = 67108864,
        private int $maxPackageBytes = 268435456,
    ) {
        $root = realpath($addonsRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new InvalidArgumentException('Add-on root directory does not exist.');
        }
        if ($this->maxEntries < 1 || $this->maxEntries > 50000
            || $this->maxFileBytes < 1024 || $this->maxPackageBytes < $this->maxFileBytes
        ) {
            throw new InvalidArgumentException('Add-on package inspection limits are invalid.');
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function inspect(string $packageDirectory): AddonPackage
    {
        $packageRoot = realpath($packageDirectory);
        if (!is_string($packageRoot) || !is_dir($packageRoot)) {
            throw new InvalidArgumentException('Add-on package directory does not exist.');
        }
        $prefix = $this->root . DIRECTORY_SEPARATOR;
        if (!str_starts_with($packageRoot . DIRECTORY_SEPARATOR, $prefix)) {
            throw new InvalidArgumentException('Add-on package is outside the configured add-on root.');
        }

        $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'addon.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new InvalidArgumentException('Add-on package must contain a regular addon.json manifest.');
        }
        $manifestSize = filesize($manifestPath);
        if (!is_int($manifestSize) || $manifestSize < 2 || $manifestSize > 131072) {
            throw new InvalidArgumentException('Add-on manifest file size is invalid.');
        }
        $json = file_get_contents($manifestPath);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read add-on manifest.');
        }
        $manifest = AddonManifest::fromJson($json);

        $expected = $this->root . DIRECTORY_SEPARATOR . $manifest->id->vendor()
            . DIRECTORY_SEPARATOR . $manifest->id->name();
        if ($packageRoot !== $expected) {
            throw new InvalidArgumentException('Add-on package path must match its Vendor/AddOn id.');
        }

        $entries = 0;
        $totalBytes = 0;
        $fingerprints = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            ++$entries;
            if ($entries > $this->maxEntries) {
                throw new InvalidArgumentException('Add-on package contains too many filesystem entries.');
            }
            if ($entry->isLink()) {
                throw new InvalidArgumentException('Add-on packages may not contain symbolic links.');
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new InvalidArgumentException('Add-on package contains an unsupported filesystem entry.');
            }
            $size = $entry->getSize();
            if ($size < 0 || $size > $this->maxFileBytes) {
                throw new InvalidArgumentException('Add-on package file exceeds the allowed size.');
            }
            $totalBytes += $size;
            if ($totalBytes > $this->maxPackageBytes) {
                throw new InvalidArgumentException('Add-on package exceeds the allowed total size.');
            }
            $path = $entry->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($packageRoot) + 1));
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new RuntimeException('Unable to checksum add-on package file.');
            }
            $fingerprints[$relative] = $size . ':' . $hash;
        }
        ksort($fingerprints, SORT_STRING);

        $context = hash_init('sha256');
        foreach ($fingerprints as $path=>$fingerprint) {
            hash_update($context, $path . "\0" . $fingerprint . "\n");
        }

        return new AddonPackage($manifest, $packageRoot, hash_final($context));
    }
}
