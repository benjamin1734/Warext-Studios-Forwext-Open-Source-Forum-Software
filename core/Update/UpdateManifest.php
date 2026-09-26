<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use Forwext\Core\Migration\SemanticVersion;
use JsonException;

final readonly class UpdateManifest
{
    /**
     * @param list<string> $add
     * @param list<string> $replace
     * @param list<string> $delete
     * @param list<string> $preserve
     * @param list<string> $migrations
     * @param list<string> $rebuild
     * @param array<string,string> $checksums
     */
    private function __construct(
        public SemanticVersion $sourceVersion,
        public SemanticVersion $targetVersion,
        public array $add,
        public array $replace,
        public array $delete,
        public array $preserve,
        public array $migrations,
        public array $rebuild,
        public array $checksums,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateException('Update manifest contains invalid JSON.', previous: $exception);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new UpdateException('Update manifest schema is unsupported.');
        }
        if (($data['checksum_algorithm'] ?? null) !== 'sha256') {
            throw new UpdateException('Update manifest checksum algorithm must be sha256.');
        }

        $source = self::version($data, 'source_version');
        $target = self::version($data, 'target_version');
        if (!$target->isGreaterThan($source)) {
            throw new UpdateException('Update target version must be newer than source version.');
        }

        $add = self::pathList($data, 'add');
        $replace = self::pathList($data, 'replace');
        $delete = self::pathList($data, 'delete');
        $preserve = self::preserveList($data);
        $migrations = self::pathList($data, 'migrations');
        $rebuild = self::actionList($data);

        $operations = array_merge($add, $replace, $delete);
        if (count($operations) !== count(array_unique($operations))) {
            throw new UpdateException('Update add/replace/delete operations must be disjoint.');
        }

        $changed = array_merge($add, $replace);
        $changedMap = array_fill_keys($changed, true);
        foreach ($migrations as $migration) {
            if (
                !isset($changedMap[$migration])
                || !str_starts_with($migration, 'database/migrations/')
                || !str_ends_with($migration, '.php')
            ) {
                throw new UpdateException('Update migration list contains an invalid payload path.');
            }
        }

        $checksumRaw = $data['checksum'] ?? null;
        if (!is_array($checksumRaw) || array_is_list($checksumRaw)) {
            throw new UpdateException('Update checksum map is invalid.');
        }

        $checksums = [];
        foreach ($checksumRaw as $path => $checksum) {
            if (
                !is_string($path)
                || !self::safePath($path)
                || !is_string($checksum)
                || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            ) {
                throw new UpdateException('Update checksum entry is invalid.');
            }
            $checksums[$path] = $checksum;
        }

        $checksumPaths = array_keys($checksums);
        sort($checksumPaths, SORT_STRING);
        $expectedPaths = $changed;
        sort($expectedPaths, SORT_STRING);
        if ($checksumPaths !== $expectedPaths) {
            throw new UpdateException('Update checksum map must exactly cover add and replace files.');
        }
        ksort($checksums, SORT_STRING);

        return new self(
            $source,
            $target,
            $add,
            $replace,
            $delete,
            $preserve,
            $migrations,
            $rebuild,
            $checksums,
        );
    }

    /** @return list<string> */
    public function changedFiles(): array
    {
        return array_merge($this->add, $this->replace);
    }

    /** @param array<string,mixed> $data */
    private static function version(array $data, string $key): SemanticVersion
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 64) {
            throw new UpdateException('Update manifest version metadata is invalid.');
        }

        try {
            return SemanticVersion::parse($value);
        } catch (\InvalidArgumentException $exception) {
            throw new UpdateException('Update manifest version metadata is invalid.', previous: $exception);
        }
    }

    /** @param array<string,mixed> $data @return list<string> */
    private static function pathList(array $data, string $key): array
    {
        $raw = $data[$key] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 100000) {
            throw new UpdateException('Update manifest path list is invalid: ' . $key);
        }

        $result = [];
        foreach ($raw as $path) {
            if (!is_string($path) || !self::safePath($path)) {
                throw new UpdateException('Update manifest contains an unsafe path: ' . $key);
            }
            $result[] = $path;
        }

        $sorted = $result;
        sort($sorted, SORT_STRING);
        if ($sorted !== $result || count($result) !== count(array_unique($result))) {
            throw new UpdateException('Update manifest path lists must be sorted and unique: ' . $key);
        }

        return $result;
    }

    /** @param array<string,mixed> $data @return list<string> */
    private static function preserveList(array $data): array
    {
        $raw = $data['preserve'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 1000) {
            throw new UpdateException('Update preserve list is invalid.');
        }

        $result = [];
        foreach ($raw as $path) {
            if (!is_string($path) || $path === '' || strlen($path) > 4096 || str_contains($path, "\0")) {
                throw new UpdateException('Update preserve entry is invalid.');
            }
            $base = str_ends_with($path, '/**') ? substr($path, 0, -3) : $path;
            if (!self::safePath($base)) {
                throw new UpdateException('Update preserve entry is unsafe.');
            }
            $result[] = $path;
        }

        $sorted = $result;
        sort($sorted, SORT_STRING);
        if ($sorted !== $result || count($result) !== count(array_unique($result))) {
            throw new UpdateException('Update preserve entries must be sorted and unique.');
        }

        return $result;
    }

    /** @param array<string,mixed> $data @return list<string> */
    private static function actionList(array $data): array
    {
        $raw = $data['rebuild'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 100) {
            throw new UpdateException('Update rebuild action list is invalid.');
        }

        $result = [];
        foreach ($raw as $action) {
            if (!is_string($action) || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $action) !== 1) {
                throw new UpdateException('Update rebuild action is invalid.');
            }
            $result[] = $action;
        }

        $sorted = $result;
        sort($sorted, SORT_STRING);
        if ($sorted !== $result || count($result) !== count(array_unique($result))) {
            throw new UpdateException('Update rebuild actions must be sorted and unique.');
        }

        return $result;
    }

    private static function safePath(string $path): bool
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

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }
}
