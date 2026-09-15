<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseRoleAppearanceRepository implements RoleAppearanceRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function find(EntityId $roleId): ?RoleAppearance
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `role_id`, `text_color`, `gradient_from`, `gradient_to`, `gradient_angle`, '
            . '`icon`, `banner_text`, `banner_color`, `pattern`, `animation`, '
            . '`show_mobile`, `show_profile`, `show_posts` '
            . 'FROM `forwext_role_appearances` WHERE `role_id` = :role_id LIMIT 1',
            ['role_id' => $roleId->value()],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(RoleAppearance $appearance): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_role_appearances` '
            . '(`role_id`, `text_color`, `gradient_from`, `gradient_to`, `gradient_angle`, `icon`, '
            . '`banner_text`, `banner_color`, `pattern`, `animation`, `show_mobile`, `show_profile`, `show_posts`) '
            . 'VALUES (:role_id, :text_color, :gradient_from, :gradient_to, :gradient_angle, :icon, '
            . ':banner_text, :banner_color, :pattern, :animation, :show_mobile, :show_profile, :show_posts) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`text_color` = VALUES(`text_color`), `gradient_from` = VALUES(`gradient_from`), '
            . '`gradient_to` = VALUES(`gradient_to`), `gradient_angle` = VALUES(`gradient_angle`), '
            . '`icon` = VALUES(`icon`), `banner_text` = VALUES(`banner_text`), '
            . '`banner_color` = VALUES(`banner_color`), `pattern` = VALUES(`pattern`), '
            . '`animation` = VALUES(`animation`), `show_mobile` = VALUES(`show_mobile`), '
            . '`show_profile` = VALUES(`show_profile`), `show_posts` = VALUES(`show_posts`)',
            [
                'role_id' => $appearance->roleId()->value(),
                'text_color' => $appearance->textColor()?->value(),
                'gradient_from' => $appearance->gradientFrom()?->value(),
                'gradient_to' => $appearance->gradientTo()?->value(),
                'gradient_angle' => $appearance->gradientAngle(),
                'icon' => $appearance->icon()?->value,
                'banner_text' => $appearance->bannerText(),
                'banner_color' => $appearance->bannerColor()?->value(),
                'pattern' => $appearance->pattern()->value,
                'animation' => $appearance->animation()->value,
                'show_mobile' => $appearance->showMobile(),
                'show_profile' => $appearance->showProfile(),
                'show_posts' => $appearance->showPosts(),
            ],
        ));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RoleAppearance
    {
        return new RoleAppearance(
            EntityId::fromString((string) $row['role_id']),
            $this->color($row['text_color'] ?? null),
            $this->color($row['gradient_from'] ?? null),
            $this->color($row['gradient_to'] ?? null),
            (int) $row['gradient_angle'],
            ($row['icon'] ?? null) === null ? null : RoleAppearanceIcon::from((string) $row['icon']),
            ($row['banner_text'] ?? null) === null ? null : (string) $row['banner_text'],
            $this->color($row['banner_color'] ?? null),
            RoleAppearancePattern::from((string) $row['pattern']),
            RoleAppearanceAnimation::from((string) $row['animation']),
            (bool) $row['show_mobile'],
            (bool) $row['show_profile'],
            (bool) $row['show_posts'],
        );
    }

    private function color(mixed $value): ?RoleColor
    {
        return $value === null ? null : RoleColor::fromHex((string) $value);
    }
}
