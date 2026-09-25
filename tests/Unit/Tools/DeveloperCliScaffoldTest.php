<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Tools;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonVersion;
use Forwext\Tools\Addon\AddonCodeGenerator;
use Forwext\Tools\Addon\AddonScaffolder;
use Forwext\Tools\Cli\CliApplication;
use Forwext\Tools\Cli\Command\AddonCreateCommand;
use Forwext\Tools\Cli\Command\MakeClassCommand;
use PHPUnit\Framework\TestCase;

final class DeveloperCliScaffoldTest extends TestCase
{
    public function testAddonCreateAndMakeGeneratorsProduceValidatedNamespacedFiles(): void
    {
        $root = $this->projectFixture();

        try {
            $scaffolder = new AddonScaffolder($root);
            $files = $scaffolder->create(
                AddonId::fromString('Acme/Demo'),
                AddonVersion::parse('1.2.3'),
            );
            self::assertContains('addons/Acme/Demo/addon.json', $files);

            $manifest = AddonManifest::fromJson((string) file_get_contents($root . '/addons/Acme/Demo/addon.json'));
            self::assertSame('Acme/Demo', $manifest->id->value());
            self::assertSame('1.2.3', $manifest->version->value());

            $generator = new AddonCodeGenerator($root);
            $entity = $generator->generate(AddonId::fromString('Acme/Demo'), 'entity', 'ExampleRecord');
            self::assertSame('addons/Acme/Demo/src/Entity/ExampleRecord.php', $entity);
            $source = (string) file_get_contents($root . '/' . $entity);
            self::assertStringContainsString('declare(strict_types=1);', $source);
            self::assertStringContainsString('implements Entity', $source);
            self::assertStringContainsString('namespace Acme\\Demo\\Entity;', $source);
        } finally {
            $this->remove($root);
        }
    }

    public function testCliDispatchesAddonCreateAndMakeServiceCommands(): void
    {
        $root = $this->projectFixture();

        try {
            $scaffolder = new AddonScaffolder($root);
            $generator = new AddonCodeGenerator($root);
            $app = new CliApplication([
                new AddonCreateCommand($scaffolder),
                new MakeClassCommand($generator, 'service'),
            ]);

            $created = $app->run(['forwext', 'addon:create', 'Acme/Demo']);
            self::assertSame(0, $created->exitCode);
            self::assertStringContainsString('Created Acme/Demo', $created->stdout);

            $generated = $app->run(['forwext', 'make:service', 'Acme/Demo', 'GreetingService']);
            self::assertSame(0, $generated->exitCode);
            self::assertFileExists($root . '/addons/Acme/Demo/src/Service/GreetingService.php');

            $duplicate = $app->run(['forwext', 'addon:create', 'Acme/Demo']);
            self::assertSame(1, $duplicate->exitCode);
            self::assertStringContainsString('already exists', $duplicate->stderr);
        } finally {
            $this->remove($root);
        }
    }

    private function projectFixture(): string
    {
        $root = sys_get_temp_dir() . '/forwext-cli-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);
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
