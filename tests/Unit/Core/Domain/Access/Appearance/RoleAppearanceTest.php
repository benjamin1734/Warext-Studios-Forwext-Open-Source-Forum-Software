<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Appearance;

use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceAnimation;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceIcon;
use Forwext\Core\Domain\Access\Appearance\RoleAppearancePattern;
use Forwext\Core\Domain\Access\Appearance\RoleColor;
use Forwext\Core\Domain\Access\Appearance\RoleDisplayContext;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoleAppearanceTest extends TestCase
{
    public function testColorIsCanonicalAndAppearanceExposesSafePresentationValues(): void
    {
        $appearance = new RoleAppearance(
            EntityId::fromString('role:moderator'),
            RoleColor::fromHex('#aabbcc'),
            RoleColor::fromHex('#112233'),
            RoleColor::fromHex('#445566'),
            135,
            RoleAppearanceIcon::Shield,
            'Forum Moderator',
            RoleColor::fromHex('#010203'),
            RoleAppearancePattern::Stripes,
            RoleAppearanceAnimation::Glow,
            false,
            true,
            false,
        );

        self::assertSame('#AABBCC', $appearance->textColor()?->value());
        self::assertTrue($appearance->hasGradient());
        self::assertSame(135, $appearance->gradientAngle());
        self::assertSame(RoleAppearanceIcon::Shield, $appearance->icon());
        self::assertTrue($appearance->isVisibleIn(RoleDisplayContext::Profile, false));
        self::assertFalse($appearance->isVisibleIn(RoleDisplayContext::Post, false));
        self::assertFalse($appearance->isVisibleIn(RoleDisplayContext::Profile, true));
    }

    public function testGradientRequiresBothColors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoleAppearance(
            EntityId::fromString('role:member'),
            gradientFrom: RoleColor::fromHex('#112233'),
        );
    }

    public function testRejectsInvalidColorAndUnsafeBannerControlCharacters(): void
    {
        try {
            RoleColor::fromHex('red;display:none');
            self::fail('Invalid CSS-like color must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        new RoleAppearance(
            EntityId::fromString('role:member'),
            bannerText: "Member\x00Hidden",
        );
    }

    public function testRejectsOutOfRangeGradientAngle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoleAppearance(
            EntityId::fromString('role:member'),
            gradientAngle: 361,
        );
    }
}
