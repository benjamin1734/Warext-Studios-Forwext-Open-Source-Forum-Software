<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Appearance;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\Appearance\DatabaseRoleAppearanceRepository;
use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceAnimation;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceIcon;
use Forwext\Core\Domain\Access\Appearance\RoleAppearancePattern;
use Forwext\Core\Domain\Access\Appearance\RoleColor;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class DatabaseRoleAppearanceRepositoryTest extends TestCase
{
    public function testFindHydratesValidatedAppearance(): void
    {
        $database = new RoleAppearanceRecordingDatabase();
        $database->row = [
            'role_id' => 'role:moderator',
            'text_color' => '#AABBCC',
            'gradient_from' => '#112233',
            'gradient_to' => '#445566',
            'gradient_angle' => 45,
            'icon' => 'shield',
            'banner_text' => 'Moderator',
            'banner_color' => '#778899',
            'pattern' => 'grid',
            'animation' => 'glow',
            'show_mobile' => 1,
            'show_profile' => 1,
            'show_posts' => 0,
        ];

        $appearance = (new DatabaseRoleAppearanceRepository($database))
            ->find(EntityId::fromString('role:moderator'));

        self::assertNotNull($appearance);
        self::assertSame('#AABBCC', $appearance->textColor()?->value());
        self::assertSame(RoleAppearanceIcon::Shield, $appearance->icon());
        self::assertSame(RoleAppearancePattern::Grid, $appearance->pattern());
        self::assertSame(RoleAppearanceAnimation::Glow, $appearance->animation());
        self::assertFalse($appearance->showPosts());
    }

    public function testSaveUsesParametersInsteadOfInterpolatingPresentationData(): void
    {
        $database = new RoleAppearanceRecordingDatabase();
        $appearance = new RoleAppearance(
            EntityId::fromString('role:moderator'),
            RoleColor::fromHex('#AABBCC'),
            bannerText: '<Staff>',
            pattern: RoleAppearancePattern::Stripes,
            animation: RoleAppearanceAnimation::Pulse,
        );

        (new DatabaseRoleAppearanceRepository($database))->save($appearance);

        self::assertNotNull($database->executed);
        self::assertStringNotContainsString('<Staff>', $database->executed->sql);
        self::assertSame('<Staff>', $database->executed->parameters['banner_text']);
        self::assertSame('role:moderator', $database->executed->parameters['role_id']);
        self::assertSame('stripes', $database->executed->parameters['pattern']);
    }
}

final class RoleAppearanceRecordingDatabase implements QueryExecutor
{
    /** @var array<string, mixed>|null */
    public ?array $row = null;
    public ?CompiledQuery $executed = null;

    public function execute(CompiledQuery $query): int
    {
        $this->executed = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return $this->row;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }
}
