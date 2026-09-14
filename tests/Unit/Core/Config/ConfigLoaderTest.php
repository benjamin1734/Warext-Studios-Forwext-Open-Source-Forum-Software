<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Config;

use Forwext\Core\Config\ConfigException;
use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\Environment;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-config-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testPrecedenceIsDefaultsThenGeneratedThenEnvironment(): void
    {
        $defaults = $this->writeConfig('defaults.php', [
            'app' => ['environment' => 'production', 'debug' => false, 'name' => 'Default'],
            'feature' => ['limit' => 10],
        ]);
        $generated = $this->writeConfig('generated.php', [
            'app' => ['name' => 'Generated'],
            'feature' => ['limit' => 20],
        ]);

        $config = (new ConfigLoader())->load($defaults, $generated, [
            'FORWEXT_CONFIG__APP__ENVIRONMENT' => 'maintenance',
            'FORWEXT_CONFIG__APP__DEBUG' => 'true',
            'FORWEXT_CONFIG__FEATURE__LIMIT' => '30',
        ]);

        self::assertSame('Generated', $config->requireString('app.name'));
        self::assertTrue($config->requireBool('app.debug'));
        self::assertSame(30, $config->requireInt('feature.limit'));
        self::assertSame(Environment::Maintenance, $config->environment());
    }

    public function testInvalidGeneratedConfigFailsClosed(): void
    {
        $defaults = $this->writeConfig('defaults.php', ['app' => ['environment' => 'production']]);
        $generated = $this->directory . '/generated.php';
        file_put_contents($generated, "<?php\ndeclare(strict_types=1);\nreturn 'invalid';\n");

        $this->expectException(ConfigException::class);
        (new ConfigLoader())->load($defaults, $generated, []);
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(string $name, array $config): string
    {
        $path = $this->directory . '/' . $name;
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($path, $contents);
        return $path;
    }
}
