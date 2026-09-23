<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Appearance\ComponentAppearanceRegistry;
use Forwext\Core\Ui\Appearance\ComponentAppearanceTarget;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ComponentAppearanceRegistryTest extends TestCase
{
    public function testCoreRegistryDefinesEveryRequiredComponentAndValidTokenReference(): void
    {
        $tokens = DesignTokenCatalog::coreDefaults();
        $registry = ComponentAppearanceRegistry::coreDefaults($tokens);

        self::assertSame(1, $registry->manifestVersion);
        self::assertCount(count(ComponentAppearanceTarget::cases()), $registry->definitions());

        foreach (ComponentAppearanceTarget::cases() as $target) {
            $definition = $registry->definition($target);
            self::assertSame($target, $definition->target);
            self::assertNotEmpty($definition->bindings);

            foreach ($definition->bindings as $tokenKey) {
                self::assertSame($tokenKey, $tokens->definition($tokenKey)->key);
            }
        }
    }

    public function testRegistryRejectsUnknownDesignTokenBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $tokens = DesignTokenCatalog::coreDefaults();
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/resources/appearance/forwext-components-default.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $manifest['components'][0]['tokens']['background'] = 'semantic.does-not-exist';

        ComponentAppearanceRegistry::fromJson(
            (string) json_encode($manifest, JSON_THROW_ON_ERROR),
            $tokens,
        );
    }
}
