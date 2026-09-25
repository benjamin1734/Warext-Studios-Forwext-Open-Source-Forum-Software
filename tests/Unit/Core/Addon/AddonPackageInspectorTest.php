<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonPackageInspector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonPackageInspectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/forwext-addon-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/Acme/Demo/src', 0777, true));
        file_put_contents($this->root . '/Acme/Demo/addon.json', self::manifest('Acme/Demo'));
        file_put_contents($this->root . '/Acme/Demo/src/Probe.php', "<?php\n\ndeclare(strict_types=1);\n");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    public function testInspectorEnforcesCanonicalPathAndProducesDeterministicChecksum(): void
    {
        $inspector = new AddonPackageInspector($this->root);

        $first = $inspector->inspect($this->root . '/Acme/Demo');
        $second = $inspector->inspect($this->root . '/Acme/Demo');

        self::assertSame('Acme/Demo', $first->manifest->id->value());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first->checksum);
        self::assertSame($first->checksum, $second->checksum);
    }

    public function testInspectorRejectsManifestWhoseIdDoesNotMatchPackagePath(): void
    {
        file_put_contents($this->root . '/Acme/Demo/addon.json', self::manifest('Other/Demo'));

        $this->expectException(InvalidArgumentException::class);
        (new AddonPackageInspector($this->root))->inspect($this->root . '/Acme/Demo');
    }

    private static function manifest(string $id): string
    {
        return (string) json_encode([
            'id'=>$id,
            'version'=>'1.0.0',
            'title'=>'Demo',
            'description'=>'Package inspector fixture.',
            'requires'=>['forwext'=>'1.0.0','addons'=>[]],
            'conflicts'=>['addons'=>[]],
            'data_retention'=>'retain_only',
        ], JSON_THROW_ON_ERROR);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
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
            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target) && !is_link($target)) {
                $this->deleteTree($target);
            } else {
                @unlink($target);
            }
        }
        @rmdir($path);
    }
}
