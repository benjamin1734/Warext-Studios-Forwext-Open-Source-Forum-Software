<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use ZipArchive;

final readonly class UpdatePackageInspector
{
    private const MAX_FILES = 50000;
    private const MAX_FILE_BYTES = 134217728;
    private const MAX_TOTAL_BYTES = 1073741824;

    /** @var list<string> */
    private const REQUIRED_PRESERVE = [
        'config/generated.php',
        'config/secret.key',
        'public/storage/**',
        'storage/backups/**',
        'storage/files/**',
        'storage/install/installed-version.json',
        'storage/logs/**',
        'storage/secrets/**',
    ];

    /** @var list<string> */
    private const PROTECTED_EXACT = [
        'config/generated.php',
        'config/secret.key',
        'storage/install/installed-version.json',
    ];

    /** @var list<string> */
    private const PROTECTED_PREFIXES = [
        'public/storage/',
        'storage/backups/',
        'storage/files/',
        'storage/logs/',
        'storage/secrets/',
    ];

    public function inspect(string $packagePath): VerifiedUpdatePackage
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('PHP ZIP extension is required to apply Forwext update packages.');
        }
        if (!is_file($packagePath) || is_link($packagePath)) {
            throw new UpdateException('Update package is missing or unsafe.');
        }

        $archive = new ZipArchive();
        if ($archive->open($packagePath) !== true) {
            throw new UpdateException('Update ZIP could not be opened.');
        }

        try {
            if ($archive->numFiles < 1 || $archive->numFiles > self::MAX_FILES) {
                throw new UpdateException('Update ZIP contains an invalid number of entries.');
            }

            $files = [];
            $totalBytes = 0;
            for ($index = 0; $index < $archive->numFiles; ++$index) {
                $stat = $archive->statIndex($index);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new UpdateException('Update ZIP contains an unreadable entry.');
                }

                $name = $stat['name'];
                if (!self::safeArchivePath($name)) {
                    throw new UpdateException('Update ZIP contains an unsafe archive path.');
                }
                if (self::isSymlink($archive, $index)) {
                    throw new UpdateException('Update ZIP may not contain symbolic links.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (isset($files[$name])) {
                    throw new UpdateException('Update ZIP contains duplicate file entries.');
                }

                $size = $stat['size'] ?? null;
                if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_BYTES) {
                    throw new UpdateException('Update ZIP entry exceeds the allowed size.');
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    throw new UpdateException('Update ZIP uncompressed payload is too large.');
                }

                $files[$name] = true;
            }

            if (!isset($files['update-manifest.json'])) {
                throw new UpdateException('Update ZIP is missing update-manifest.json.');
            }
            $manifestJson = $archive->getFromName('update-manifest.json');
            if ($manifestJson === false || strlen($manifestJson) > 8388608) {
                throw new UpdateException('Update manifest is missing or too large.');
            }
            $manifest = UpdateManifest::fromJson($manifestJson);

            $missingPreserve = array_diff(self::REQUIRED_PRESERVE, $manifest->preserve);
            if ($missingPreserve !== []) {
                throw new UpdateException('Update manifest is missing mandatory protected paths.');
            }

            foreach (array_merge($manifest->add, $manifest->replace, $manifest->delete) as $path) {
                if (self::protectedPath($path)) {
                    throw new UpdateException('Update manifest attempts to change protected mutable site data.');
                }
            }

            $payloadNames = array_keys($files);
            $payloadNames = array_values(array_filter(
                $payloadNames,
                static fn (string $name): bool => $name !== 'update-manifest.json',
            ));
            sort($payloadNames, SORT_STRING);

            $expectedPayload = $manifest->changedFiles();
            sort($expectedPayload, SORT_STRING);
            if ($payloadNames !== $expectedPayload) {
                throw new UpdateException('Update ZIP payload does not exactly match manifest add/replace files.');
            }

            foreach ($manifest->checksums as $path => $expectedChecksum) {
                $payload = $archive->getFromName($path);
                if ($payload === false || !hash_equals($expectedChecksum, hash('sha256', $payload))) {
                    throw new UpdateException('Update ZIP payload checksum validation failed.');
                }
            }

            return new VerifiedUpdatePackage($packagePath, $manifest);
        } finally {
            $archive->close();
        }
    }

    private static function safeArchivePath(string $path): bool
    {
        if (
            $path === ''
            || strlen($path) > 4096
            || $path[0] === '/'
            || str_contains($path, "\\")
            || str_contains($path, "\0")
        ) {
            return false;
        }

        $trimmed = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if ($trimmed === '') {
            return false;
        }
        foreach (explode('/', $trimmed) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function protectedPath(string $path): bool
    {
        if (in_array($path, self::PROTECTED_EXACT, true)) {
            return true;
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isSymlink(ZipArchive $archive, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;
        if (!$archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }
        if ($operatingSystem !== 3) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === 0xA000;
    }
}
