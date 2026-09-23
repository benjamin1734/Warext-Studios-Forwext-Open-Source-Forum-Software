<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Appearance\Background\BackgroundRegistry;
use Forwext\Core\Ui\Appearance\Background\BackgroundScope;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackgroundRegistryTest extends TestCase
{
    public function testCoreDefaultsUseSiteAndHeaderScopesWithoutExternalAssets(): void
    {
        $registry = BackgroundRegistry::coreDefaults(DesignTokenCatalog::coreDefaults());
        $definitions = $registry->definitions();

        self::assertSame(1, $registry->manifestVersion);
        self::assertCount(2, $definitions);
        self::assertSame(
            [BackgroundScope::Header, BackgroundScope::Site],
            array_map(static fn ($definition) => $definition->scope, $definitions),
        );

        foreach ($definitions as $definition) {
            self::assertNull($definition->asset);
        }
    }

    public function testRegistryRejectsNonColorTokenForBackgroundColor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BackgroundRegistry::fromJson((string) json_encode([
            'version' => 1,
            'backgrounds' => [[
                'key' => 'site.bad',
                'scope' => 'site',
                'scope_id' => null,
                'kind' => 'solid',
                'colors' => ['spacing.4'],
                'asset' => null,
                'pattern' => null,
            ]],
        ], JSON_THROW_ON_ERROR), DesignTokenCatalog::coreDefaults());
    }
}
