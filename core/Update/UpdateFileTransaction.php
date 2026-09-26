<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use JsonException;

final readonly class UpdateFileTransaction
{
    public function __construct(
        private string $projectRoot,
        private string $snapshotRoot,
    ) {
        if ($this->projectRoot === '' || $this->snapshotRoot === '') {
            throw new UpdateException('Update file transaction paths are invalid.');
        }
    }

    public function apply(VerifiedUpdatePackage $package): UpdateFileSnapshot
    {
        $this->assertRoot($this->projectRoot, false);
        $this->assertRoot($this->snapshotRoot, true);

        foreach ($package->manifest->add as $path) {
            $target = $this->target($path);
            $this->assertNoSymlinkParents($target);
            if (file_exists($target) || is_link($target)) {
                throw new UpdateException('Update add target already exists: ' . $path);
            }
        }
        foreach (array_merge($package->manifest->replace, $package->manifest->delete) as $path) {
            $target = $this->target($path);
            $this->assertNoSymlinkParents($target);
            if (!is_file($target) || is_link($target)) {
                throw new UpdateException('Update source file is missing or unsafe: ' . $path);
            }
        }

        $id = bin2hex(random_bytes(16));
        $directory = rtrim($this->snapshotRoot, '/\\') . '/' . $id;
        if (!@mkdir($directory, 0700, true)) {
            throw new UpdateException('Update file snapshot directory cannot be created.');
        }

        $backups = [];
        foreach (array_merge($package->manifest->replace, $package->manifest->delete) as $path) {
            $source = $this->target($path);
            $backup = $directory . '/files/' . $path;
            $this->ensureParent($backup);
            if (!@copy($source, $backup)) {
                throw new UpdateException('Update file snapshot could not copy: ' . $path);
            }
            @chmod($backup, 0600);
            $hash = hash_file('sha256', $backup);
            if (!is_string($hash)) {
                throw new UpdateException('Update file snapshot checksum failed.');
            }
            $backups[$path] = $hash;
        }
        ksort($backups, SORT_STRING);

        $snapshot = new UpdateFileSnapshot($id, $directory, $package->manifest->add, $backups);
        $this->writeSnapshotManifest($snapshot, $package);

        try {
            foreach ($package->manifest->changedFiles() as $path) {
                $this->atomicWrite($this->target($path), $package->payload($path));
            }
            foreach ($package->manifest->delete as $path) {
                $target = $this->target($path);
                if (!@unlink($target)) {
                    throw new UpdateException('Update could not delete obsolete file: ' . $path);
                }
            }
        } catch (\Throwable $failure) {
            try {
                $this->rollback($snapshot);
            } catch (\Throwable $rollbackFailure) {
                throw new UpdateException(
                    'Update file application failed and automatic file rollback also failed.',
                    previous: $rollbackFailure,
                );
            }
            throw new UpdateException('Update file application failed; original files were restored.', previous: $failure);
        }

        return $snapshot;
    }

    public function rollback(UpdateFileSnapshot $snapshot): void
    {
        $this->verifySnapshot($snapshot);

        foreach ($snapshot->added as $path) {
            $target = $this->target($path);
            $this->assertNoSymlinkParents($target);
            if (is_link($target)) {
                throw new UpdateException('Update rollback add target became a symbolic link.');
            }
            if (is_file($target) && !@unlink($target)) {
                throw new UpdateException('Update rollback could not remove added file: ' . $path);
            }
        }

        foreach ($snapshot->backups as $path => $expectedHash) {
            $backup = $snapshot->directory . '/files/' . $path;
            if (!is_file($backup) || is_link($backup)) {
                throw new UpdateException('Update rollback snapshot file is missing or unsafe.');
            }
            $actualHash = hash_file('sha256', $backup);
            if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
                throw new UpdateException('Update rollback snapshot checksum mismatch.');
            }
            $payload = @file_get_contents($backup);
            if ($payload === false) {
                throw new UpdateException('Update rollback snapshot file cannot be read.');
            }
            $this->atomicWrite($this->target($path), $payload);
        }
    }

    private function writeSnapshotManifest(UpdateFileSnapshot $snapshot, VerifiedUpdatePackage $package): void
    {
        $payload = json_encode([
            'schema'=>1,
            'id'=>$snapshot->id,
            'source_version'=>$package->manifest->sourceVersion->value(),
            'target_version'=>$package->manifest->targetVersion->value(),
            'added'=>$snapshot->added,
            'backups'=>$snapshot->backups,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $this->atomicWrite($snapshot->directory . '/snapshot-manifest.json', $payload, 0600);
    }

    private function verifySnapshot(UpdateFileSnapshot $snapshot): void
    {
        $manifestPath = $snapshot->directory . '/snapshot-manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new UpdateException('Update rollback snapshot manifest is missing or unsafe.');
        }
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            throw new UpdateException('Update rollback snapshot manifest cannot be read.');
        }
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateException('Update rollback snapshot manifest is corrupt.', previous: $exception);
        }
        if (
            !is_array($manifest)
            || ($manifest['schema'] ?? null) !== 1
            || ($manifest['id'] ?? null) !== $snapshot->id
            || ($manifest['added'] ?? null) !== $snapshot->added
            || ($manifest['backups'] ?? null) !== $snapshot->backups
        ) {
            throw new UpdateException('Update rollback snapshot manifest does not match the active transaction.');
        }
    }

    private function target(string $relative): string
    {
        return rtrim($this->projectRoot, '/\\') . '/' . $relative;
    }

    private function assertRoot(string $directory, bool $create): void
    {
        if (is_link($directory)) {
            throw new UpdateException('Update transaction root may not be a symbolic link.');
        }
        if ($create && !is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UpdateException('Update transaction root cannot be created.');
        }
        if (!is_dir($directory)) {
            throw new UpdateException('Update transaction root is unavailable.');
        }
    }

    private function assertNoSymlinkParents(string $path): void
    {
        $root = rtrim($this->projectRoot, '/\\');
        $relative = substr($path, strlen($root) + 1);
        if (!is_string($relative) || $relative === '') {
            throw new UpdateException('Update target path is invalid.');
        }

        $cursor = $root;
        $parts = explode('/', str_replace('\\', '/', dirname($relative)));
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new UpdateException('Update target traverses a symbolic link.');
            }
        }
    }

    private function ensureParent(string $path): void
    {
        $directory = dirname($path);
        if (is_link($directory)) {
            throw new UpdateException('Update staging directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UpdateException('Update staging directory cannot be created.');
        }
    }

    private function atomicWrite(string $path, string $payload, int $mode = 0644): void
    {
        $this->assertNoSymlinkParentsForAnyRoot($path);
        $this->ensureParent($path);
        if (is_link($path)) {
            throw new UpdateException('Update target may not be a symbolic link.');
        }

        $temporary = $path . '.forwext-update-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new UpdateException('Update could not stage a target file.');
            }
            @chmod($temporary, $mode);
            if (!@rename($temporary, $path)) {
                throw new UpdateException('Update could not atomically publish a target file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertNoSymlinkParentsForAnyRoot(string $path): void
    {
        $root = str_starts_with($path, rtrim($this->snapshotRoot, '/\\') . '/')
            ? rtrim($this->snapshotRoot, '/\\')
            : rtrim($this->projectRoot, '/\\');
        $relative = substr($path, strlen($root) + 1);
        $cursor = $root;
        foreach (explode('/', str_replace('\\', '/', dirname((string) $relative))) as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new UpdateException('Update target traverses a symbolic link.');
            }
        }
    }
}
