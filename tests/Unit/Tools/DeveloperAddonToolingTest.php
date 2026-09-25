<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Tools;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonVersion;
use Forwext\Tools\Addon\AddonCompatibilityChecker;
use Forwext\Tools\Addon\AddonIdeTypeGenerator;
use Forwext\Tools\Addon\AddonPackageBuilder;
use Forwext\Tools\Addon\AddonScaffolder;
use Forwext\Tools\Dev\DeveloperMode;
use PHPUnit\Framework\TestCase;

final class DeveloperAddonToolingTest extends TestCase
{
    public function testCompatibilityIdeTypesAndDeterministicZipBuild(): void
    {
        $root = $this->fixture();

        try {
            (new AddonScaffolder($root))->create(
                AddonId::fromString('Acme/Demo'),
                AddonVersion::parse('1.0.0'),
            );

            $checker = new AddonCompatibilityChecker($root);
            self::assertTrue($checker->check(AddonId::fromString('Acme/Demo'))->compatible());

            $idePath = (new AddonIdeTypeGenerator($root))->generate(AddonId::fromString('Acme/Demo'));
            self::assertFileExists($root . '/' . $idePath);

            $builder = new AddonPackageBuilder($root, $checker);
            $first = $builder->build(AddonId::fromString('Acme/Demo'));
            self::assertFileExists($first->path);
            self::assertFileExists($first->checksumPath);
            self::assertStringContainsString($first->checksum, (string) file_get_contents($first->checksumPath));
            self::assertSame("PK\x03\x04", (string) file_get_contents($first->path, false, null, 0, 4));

            $second = $builder->build(
                AddonId::fromString('Acme/Demo'),
                $root . '/dist/addons/rebuilt.zip',
            );
            self::assertSame($first->checksum, $second->checksum);
        } finally {
            $this->remove($root);
        }
    }

    public function testDeveloperModeIsToolOnlyMarker(): void
    {
        $root = $this->fixture();

        try {
            $mode = new DeveloperMode($root);
            self::assertFalse($mode->isEnabled());
            $mode->enable();
            self::assertTrue($mode->isEnabled());
            self::assertFileExists($root . '/storage/dev/developer-mode.json');
            $mode->disable();
            self::assertFalse($mode->isEnabled());
        } finally {
            $this->remove($root);
        }
    }

    public function testCompatibilityCheckerRejectsPhpWithoutStrictTypes(): void
    {
        $root = $this->fixture();

        try {
            (new AddonScaffolder($root))->create(
                AddonId::fromString('Acme/Demo'),
                AddonVersion::parse('1.0.0'),
            );
            file_put_contents(
                $root . '/addons/Acme/Demo/src/UnsafeFixture.php',
                "<?php\nnamespace Acme\\Demo;\nfinal class UnsafeFixture {}\n",
            );

            $report = (new AddonCompatibilityChecker($root))->check(AddonId::fromString('Acme/Demo'));
            self::assertFalse($report->compatible());
            self::assertStringContainsString(
                'declare(strict_types=1) is required',
                implode("\n", $report->errors),
            );
        } finally {
            $this->remove($root);
        }
    }

    public function testCompatibilityCheckerContainsHighRiskTokenGate(): void
    {
        $checker = (string) file_get_contents(
            dirname(__DIR__, 3) . '/tools/Addon/AddonCompatibilityChecker.php',
        );

        self::assertStringContainsString('T_EVAL', $checker);
        self::assertStringContainsString('forbidden high-risk function call', $checker);
        self::assertStringContainsString('token_get_all($source, TOKEN_PARSE)', $checker);
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/forwext-addon-tools-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
        mkdir($root . '/storage', 0700, true);
        file_put_contents($root . '/VERSION', "0.0.7.54-dev\n");

        return $root;
    }

    private function remove(string $path): void
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
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->remove($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
