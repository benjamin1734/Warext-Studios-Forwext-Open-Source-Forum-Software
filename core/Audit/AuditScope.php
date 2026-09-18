<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

enum AuditScope: string
{
    case Moderation = 'moderation';
    case Administration = 'administration';

    public function label(): string
    {
        return match ($this) {
            self::Moderation => 'Moderasyon',
            self::Administration => 'Yönetim',
        };
    }
}
