<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

enum DisciplineActionType: string
{
    case Warning = 'warning';
    case Restriction = 'restriction';
    case Suspension = 'suspension';
    case Ban = 'ban';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Uyarı',
            self::Restriction => 'Kısıtlama',
            self::Suspension => 'Askıya alma',
            self::Ban => 'Ban',
        };
    }
}
