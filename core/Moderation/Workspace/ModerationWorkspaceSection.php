<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

enum ModerationWorkspaceSection: string
{
    case Reports = 'reports';
    case Approval = 'approval';
    case Warnings = 'warnings';
    case Bans = 'bans';
    case Tasks = 'tasks';

    public function label(): string
    {
        return match ($this) {
            self::Reports => 'Raporlar',
            self::Approval => 'Onay kuyruğu',
            self::Warnings => 'Uyarılar',
            self::Bans => 'Banlar',
            self::Tasks => 'Görevler',
        };
    }
}
