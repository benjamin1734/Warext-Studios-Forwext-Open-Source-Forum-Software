<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

enum AuditScope: string
{
    case Moderation = 'moderation';
    case Administration = 'administration';
    case Support = 'support';
    case Bug = 'bug';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Moderation => 'Moderasyon',
            self::Administration => 'Yönetim',
            self::Support => 'Destek',
            self::Bug => 'Hata Bildirimleri',
            self::Api => 'API',
        };
    }
}
