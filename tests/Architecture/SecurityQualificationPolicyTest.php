<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class SecurityQualificationPolicyTest extends TestCase
{
    public function testAllRoadmapSecurityDimensionsRemainExplicitCiGates(): void
    {
        $workflow = $this->read('.github/workflows/security-qualification.yml');

        foreach ([
            'SQL injection qualification',
            'XSS output-escaping qualification',
            'CSRF qualification',
            'SSRF qualification',
            'IDOR and BOLA authorization qualification',
            'Upload and path traversal qualification',
            'OAuth linking qualification',
            'Session qualification',
            'Webhook signing and destination qualification',
            'Secret leakage and encryption qualification',
            'Composer security advisory scan',
            'Production JavaScript advisory scan',
        ] as $gate) {
            self::assertStringContainsString($gate, $workflow);
        }

        self::assertStringContainsString("php: ['8.4', '8.5']", $workflow);
        self::assertStringContainsString('composer audit --no-interaction', $workflow);
        self::assertStringContainsString('npm audit --omit=dev --audit-level=high', $workflow);
    }

    public function testThreatModelDocumentsAssetsBoundariesAndRequiredThreats(): void
    {
        $threatModel = $this->read('docs/security/threat-model.md');

        foreach ([
            'Assets',
            'Trust boundaries',
            'SQL injection',
            'Cross-site scripting',
            'CSRF',
            'SSRF',
            'IDOR/BOLA',
            'Upload and path traversal',
            'OAuth account linking',
            'Session security',
            'Webhook security',
            'Secret leakage',
            'Dependency supply chain',
            'Residual risk',
        ] as $section) {
            self::assertStringContainsString($section, $threatModel);
        }
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read security qualification file: ' . $relativePath);
        }

        return $contents;
    }
}
