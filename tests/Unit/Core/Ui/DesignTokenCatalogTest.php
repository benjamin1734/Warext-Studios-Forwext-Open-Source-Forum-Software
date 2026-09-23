<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenCategory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DesignTokenCatalogTest extends TestCase
{
    public function testCoreManifestCoversRequiredCategoriesAndSemanticResolution(): void
    {
        $catalog = DesignTokenCatalog::coreDefaults();
        $categories = array_map(
            static fn ($definition): DesignTokenCategory => $definition->category,
            $catalog->definitions(),
        );

        foreach (DesignTokenCategory::cases() as $category) {
            self::assertContains($category, $categories);
        }

        self::assertSame('#0d1117', $catalog->resolveValue('semantic.page.background'));
        self::assertSame('#ff7a1a', $catalog->resolveValue('semantic.accent.primary'));
        self::assertSame(1, $catalog->manifestVersion);
    }

    public function testManifestRejectsReferenceCycles(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DesignTokenCatalog::fromJson((string) json_encode([
            'version' => 1,
            'tokens' => [
                ['key' => 'semantic.a', 'category' => 'semantic', 'ref' => 'semantic.b'],
                ['key' => 'semantic.b', 'category' => 'semantic', 'ref' => 'semantic.a'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testManifestRejectsCssInjectionValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DesignTokenCatalog::fromJson((string) json_encode([
            'version' => 1,
            'tokens' => [
                [
                    'key' => 'color.bad',
                    'category' => 'color',
                    'value' => '#ffffff;background:url(https://example.invalid/x)',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
