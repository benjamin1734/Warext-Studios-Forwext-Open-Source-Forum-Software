<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use FilesystemIterator;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonPackageInspector;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class AddonPackageBuilder
{
    private string $root;

    public function __construct(
        string $projectRoot,
        private AddonCompatibilityChecker $compatibility,
        private DeterministicZipWriter $zip = new DeterministicZipWriter(),
    ) {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_file($root . '/VERSION')) {
            throw new RuntimeException('Forwext project root is invalid.');
        }
        $this->root = $root;
    }

    public function build(AddonId $id, ?string $outputPath = null): AddonBuildResult
    {
        $report = $this->compatibility->check($id);
        if (!$report->compatible()) {
            throw new RuntimeException('Compatibility check failed: ' . implode(' | ', $report->errors));
        }

        $addonsRoot = $this->root . '/addons';
        $package = (new AddonPackageInspector($addonsRoot))->inspect(
            $addonsRoot . '/' . $id->vendor() . '/' . $id->name(),
        );

        $files = [];
        $checksums = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($package->rootPath, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($package->rootPath) + 1));
            if (str_starts_with($relative, '.git/')
                || str_starts_with($relative, '.forwext/')
                || str_starts_with($relative, 'tests/')
            ) {
                continue;
            }
            $content = file_get_contents($entry->getPathname());
            if (!is_string($content)) {
                throw new RuntimeException('Unable to read add-on build input: ' . $relative);
            }
            $archivePath = $id->vendor() . '/' . $id->name() . '/' . $relative;
            $files[$archivePath] = $content;
            $checksums[$relative] = hash('sha256', $content);
        }
        ksort($checksums, SORT_STRING);

        try {
            $buildManifest = json_encode([
                'schema'=>1,
                'id'=>$id->value(),
                'version'=>$package->manifest->version->value(),
                'source_checksum'=>$package->checksum,
                'capabilities'=>array_map(static fn ($capability): string => $capability->value, $package->manifest->capabilities),
                'files'=>$checksums,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode add-on build manifest.', previous:$exception);
        }
        $files[$id->vendor() . '/' . $id->name() . '/forwext-build.json'] = $buildManifest;

        $output = $outputPath;
        if ($output === null || trim($output) === '') {
            $output = $this->root . '/dist/addons/'
                . strtolower($id->vendor() . '-' . $id->name())
                . '-v' . $package->manifest->version->value() . '.zip';
        } elseif (!str_starts_with($output, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/D', $output) !== 1
        ) {
            $output = $this->root . '/' . ltrim($output, '/\\');
        }

        $this->zip->write($output, $files);
        $checksum = hash_file('sha256', $output);
        if (!is_string($checksum)) {
            throw new RuntimeException('Unable to checksum built add-on package.');
        }
        $checksumPath = $output . '.sha256';
        $checksumLine = $checksum . '  ' . basename($output) . "\n";
        $temporary = $checksumPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $checksumLine, LOCK_EX) !== strlen($checksumLine)) {
                throw new RuntimeException('Unable to write add-on checksum sidecar.');
            }
            if (!chmod($temporary, 0644) || !rename($temporary, $checksumPath)) {
                throw new RuntimeException('Unable to publish add-on checksum sidecar.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return new AddonBuildResult($output, $checksum, count($files), $checksumPath);
    }
}
