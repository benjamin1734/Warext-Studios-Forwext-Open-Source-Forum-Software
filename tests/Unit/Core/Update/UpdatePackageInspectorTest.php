<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Update;

use Forwext\Core\Update\UpdateException;
use Forwext\Core\Update\UpdatePackageInspector;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class UpdatePackageInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension is unavailable.');
        }

        $this->directory = sys_get_temp_dir() . '/forwext-update-package-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($this->directory . '/' . $name);
            }
        }
        @rmdir($this->directory);
    }

    public function testInspectorAcceptsExactChecksummedPayload(): void
    {
        $zip = $this->zip([
            'core/Example.php'=>'example',
            'VERSION'=>'version',
        ]);

        $package = (new UpdatePackageInspector())->inspect($zip);

        self::assertSame('example', $package->payload('core/Example.php'));
        self::assertSame('0.0.7.64-dev', $package->manifest->targetVersion->value());
    }

    public function testInspectorRejectsProtectedMutableFileOperation(): void
    {
        $zipPath = $this->directory . '/protected.zip';
        $archive = new ZipArchive();
        self::assertTrue($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('config/generated.php', 'attack');
        $manifest = $this->manifest([
            'config/generated.php'=>'attack',
        ]);
        $manifest['add'] = ['config/generated.php'];
        $manifest['replace'] = [];
        $manifest['checksum'] = ['config/generated.php'=>hash('sha256', 'attack')];
        $archive->addFromString('update-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $archive->close();

        $this->expectException(UpdateException::class);
        (new UpdatePackageInspector())->inspect($zipPath);
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $path = $this->directory . '/update.zip';
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $archive->addFromString($name, $contents);
        }
        $archive->addFromString('update-manifest.json', json_encode($this->manifest($files), JSON_THROW_ON_ERROR));
        $archive->close();

        return $path;
    }

    /** @param array<string,string> $files @return array<string,mixed> */
    private function manifest(array $files): array
    {
        $checksums = [];
        foreach ($files as $name => $contents) {
            $checksums[$name] = hash('sha256', $contents);
        }
        ksort($checksums, SORT_STRING);

        return [
            'schema'=>1,
            'source_version'=>'0.0.7.63-dev',
            'target_version'=>'0.0.7.64-dev',
            'add'=>array_values(array_filter(array_keys($files), static fn (string $name): bool => $name !== 'VERSION')),
            'replace'=>isset($files['VERSION']) ? ['VERSION'] : [],
            'delete'=>[],
            'preserve'=>[
                'config/generated.php',
                'config/secret.key',
                'public/storage/**',
                'storage/backups/**',
                'storage/files/**',
                'storage/install/installed-version.json',
                'storage/logs/**',
                'storage/secrets/**',
            ],
            'migrations'=>[],
            'rebuild'=>[],
            'checksum_algorithm'=>'sha256',
            'checksum'=>$checksums,
        ];
    }
}
