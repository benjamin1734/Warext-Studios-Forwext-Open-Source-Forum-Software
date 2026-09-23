<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Appearance\Background\BackgroundAssetPath;
use Forwext\Core\Ui\Appearance\Background\BackgroundBlendMode;
use Forwext\Core\Ui\Appearance\Background\BackgroundDefinition;
use Forwext\Core\Ui\Appearance\Background\BackgroundKind;
use Forwext\Core\Ui\Appearance\Background\BackgroundPattern;
use Forwext\Core\Ui\Appearance\Background\BackgroundScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackgroundDefinitionTest extends TestCase
{
    public function testAllRoadmapBackgroundKindsCanBeRepresented(): void
    {
        $site = new BackgroundDefinition(
            'site.solid',
            BackgroundScope::Site,
            null,
            BackgroundKind::Solid,
            ['semantic.page.background'],
            null,
            null,
        );
        $gradient = new BackgroundDefinition(
            'header.gradient',
            BackgroundScope::Header,
            null,
            BackgroundKind::Gradient,
            ['semantic.page.background', 'semantic.accent.primary'],
            null,
            null,
            85,
            140,
            12,
            BackgroundBlendMode::Overlay,
            true,
            8000,
        );
        $image = new BackgroundDefinition(
            'profile.image',
            BackgroundScope::Profile,
            str_repeat('a', 32),
            BackgroundKind::Image,
            [],
            BackgroundAssetPath::fromString('assets/appearance/profile/background.webp'),
            null,
            70,
            120,
            -5,
            BackgroundBlendMode::SoftLight,
        );
        $pattern = new BackgroundDefinition(
            'category.pattern',
            BackgroundScope::Category,
            str_repeat('b', 32),
            BackgroundKind::Pattern,
            ['semantic.accent.primary', 'semantic.page.background'],
            null,
            BackgroundPattern::Grid,
            45,
        );

        self::assertSame(BackgroundKind::Solid, $site->kind);
        self::assertTrue($gradient->animatedGradient);
        self::assertSame('assets/appearance/profile/background.webp', $image->asset?->value());
        self::assertSame(BackgroundPattern::Grid, $pattern->pattern);
    }

    public function testExternalOrTraversalAssetPathsAreRejected(): void
    {
        foreach ([
            'https://example.invalid/a.webp',
            '../assets/appearance/a.webp',
            'assets/appearance/../../a.webp',
            'assets/appearance/a.svg',
            'assets/appearance/a.webp?x=1',
        ] as $path) {
            try {
                BackgroundAssetPath::fromString($path);
                self::fail('Unsafe appearance asset path was accepted: ' . $path);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEntityScopedBackgroundRequiresOpaqueEntityId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackgroundDefinition(
            'profile.invalid',
            BackgroundScope::Profile,
            'admin',
            BackgroundKind::Solid,
            ['semantic.page.background'],
            null,
            null,
        );
    }
}
