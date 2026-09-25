<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

enum AdminNavigationSection: string
{
    case ModerationSupport = 'moderation-support';
    case Commerce = 'commerce';
    case Analytics = 'analytics';
    case Appearance = 'appearance';
    case Community = 'community';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::ModerationSupport => 'Moderasyon ve Destek',
            self::Commerce => 'Ticaret ve Gelir',
            self::Analytics => 'Analiz ve Raporlama',
            self::Appearance => 'Görünüm',
            self::Community => 'Topluluk Araçları',
            self::System => 'Sistem ve Entegrasyonlar',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::ModerationSupport => 10,
            self::Commerce => 20,
            self::Analytics => 30,
            self::Appearance => 40,
            self::Community => 50,
            self::System => 60,
        };
    }
}
