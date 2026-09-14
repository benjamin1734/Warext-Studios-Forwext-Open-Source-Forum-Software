<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[CoversNothing]
final class CodeQualityPolicyTest extends TestCase
{
    private const PHP_SOURCE_DIRECTORIES = [
        'app',
        'core',
        'modules',
        'addons',
        'database',
        'public',
        'tests',
        'tools',
    ];

    public function testQualityPolicyMatchesRequiredBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $policy = $this->decodeJson($root . '/docs/standards/quality-policy.json');

        self::assertSame('02.02', $policy['roadmap_step']);
        self::assertSame('>=8.4', $policy['php']['minimum']);
        self::assertSame(['8.4', '8.5'], $policy['php']['ci_targets']);
        self::assertSame('PSR-12', $policy['php']['coding_standard']);
        self::assertTrue($policy['php']['strict_types_required']);
        self::assertSame('max', $policy['static_analysis']['phpstan_level']);
        self::assertSame(1, $policy['static_analysis']['psalm_error_level']);
        self::assertTrue($policy['typescript']['strict_compiler_options']);
    }

    public function testRequiredQualityConfigurationFilesExist(): void
    {
        $root = dirname(__DIR__, 2);
        $required = [
            'composer.json',
            'phpcs.xml.dist',
            'phpstan.neon.dist',
            'psalm.xml',
            'phpunit.xml.dist',
            'package.json',
            'eslint.config.mjs',
            '.prettierrc.json',
            'tsconfig.base.json',
            'tools/quality/check-strict-types.php',
        ];

        foreach ($required as $path) {
            self::assertFileExists($root . '/' . $path, $path . ' must exist.');
        }
    }

    public function testEveryTrackedPhpSourceDeclaresStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach (self::PHP_SOURCE_DIRECTORIES as $directory) {
            $path = $root . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    throw new RuntimeException('Cannot read ' . $file->getPathname());
                }

                if (!$this->startsWithStrictTypes($contents)) {
                    $violations[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $violations, 'PHP files missing top-level declare(strict_types=1): ' . implode(', ', $violations));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function startsWithStrictTypes(string $contents): bool
    {
        return preg_match(
            '/\\A<\\?php(?:\\s|\\/\\*.*?\\*\\/|\/\/[^\\r\\n]*(?:\\R|$)|#[^\\r\\n]*(?:\\R|$))*declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/s',
            $contents
        ) === 1;
    }
}
