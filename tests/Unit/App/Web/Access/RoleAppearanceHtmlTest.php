<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Access;

use Forwext\App\Web\Access\RoleAppearanceHtml;
use Forwext\Core\Domain\Access\AccessIdentifier;
use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceAnimation;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceIcon;
use Forwext\Core\Domain\Access\Appearance\RoleAppearancePattern;
use Forwext\Core\Domain\Access\Appearance\RoleColor;
use Forwext\Core\Domain\Access\Appearance\RoleDisplayContext;
use Forwext\Core\Domain\Access\Role;
use Forwext\Core\Domain\Access\RoleKind;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RoleAppearanceHtmlTest extends TestCase
{
    public function testRendersEscapedRolePresentationWithPriorityAndSafeTokens(): void
    {
        $role = $this->role('role:moderator');
        $appearance = new RoleAppearance(
            $role->id(),
            RoleColor::fromHex('#abcdef'),
            RoleColor::fromHex('#112233'),
            RoleColor::fromHex('#445566'),
            120,
            RoleAppearanceIcon::Crown,
            '<Moderator>',
            RoleColor::fromHex('#778899'),
            RoleAppearancePattern::Dots,
            RoleAppearanceAnimation::Shimmer,
        );

        $html = (new RoleAppearanceHtml())->render($role, $appearance, RoleDisplayContext::Post);

        self::assertStringContainsString('data-role="moderator"', $html);
        self::assertStringContainsString('data-priority="500"', $html);
        self::assertStringContainsString('role-appearance--gradient', $html);
        self::assertStringContainsString('role-appearance--pattern-dots', $html);
        self::assertStringContainsString('role-appearance--animation-shimmer', $html);
        self::assertStringContainsString('role-appearance__icon--crown', $html);
        self::assertStringContainsString('--forwext-role-color:#ABCDEF', $html);
        self::assertStringContainsString('&lt;Moderator&gt;', $html);
        self::assertStringNotContainsString('<Moderator>', $html);
    }

    public function testHiddenMobileAppearanceProducesNoMarkup(): void
    {
        $role = $this->role('role:member');
        $appearance = new RoleAppearance($role->id(), showMobile: false);

        $html = (new RoleAppearanceHtml())->render(
            $role,
            $appearance,
            RoleDisplayContext::Profile,
            true,
        );

        self::assertSame('', $html);
    }

    public function testRejectsAppearanceBelongingToAnotherRole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RoleAppearanceHtml())->render(
            $this->role('role:moderator'),
            new RoleAppearance(EntityId::fromString('role:member')),
            RoleDisplayContext::Profile,
        );
    }

    private function role(string $id): Role
    {
        $key = str_ends_with($id, 'moderator') ? 'moderator' : 'member';

        return new Role(
            EntityId::fromString($id),
            AccessIdentifier::fromString($key),
            $key === 'moderator' ? 'Moderator' : 'Member',
            RoleKind::Staff,
            false,
            500,
        );
    }
}
