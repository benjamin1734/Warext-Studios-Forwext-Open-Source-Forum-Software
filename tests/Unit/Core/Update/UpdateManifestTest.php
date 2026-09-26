<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Update;

use Forwext\Core\Update\UpdateException;
use Forwext\Core\Update\UpdateManifest;
use PHPUnit\Framework\TestCase;

final class UpdateManifestTest extends TestCase
{
    public function testValidManifestParsesExactTransitionContract(): void
    {
        $payload = $this->manifest();
        $manifest = UpdateManifest::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('0.0.7.63-dev', $manifest->sourceVersion->value());
        self::assertSame('0.0.7.64-dev', $manifest->targetVersion->value());
        self::assertSame(['core/Example.php'], $manifest->add);
        self::assertSame(['VERSION'], $manifest->replace);
        self::assertSame(['old.php'], $manifest->delete);
        self::assertSame(hash('sha256', 'example'), $manifest->checksums['core/Example.php']);
    }

    public function testOverlappingOperationsAreRejected(): void
    {
        $payload = $this->manifest();
        $payload['delete'] = ['VERSION'];

        $this->expectException(UpdateException::class);
        UpdateManifest::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testChecksumCoverageMustExactlyMatchChangedPayload(): void
    {
        $payload = $this->manifest();
        unset($payload['checksum']['VERSION']);

        $this->expectException(UpdateException::class);
        UpdateManifest::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return [
            'schema'=>1,
            'source_version'=>'0.0.7.63-dev',
            'target_version'=>'0.0.7.64-dev',
            'add'=>['core/Example.php'],
            'replace'=>['VERSION'],
            'delete'=>['old.php'],
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
            'checksum'=>[
                'VERSION'=>hash('sha256', 'version'),
                'core/Example.php'=>hash('sha256', 'example'),
            ],
        ];
    }
}
