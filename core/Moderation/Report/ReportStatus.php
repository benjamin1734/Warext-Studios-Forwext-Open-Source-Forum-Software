<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

enum ReportStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function isActive(): bool
    {
        return $this === self::Open || $this === self::InReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::InReview => 'İnceleniyor',
            self::Resolved => 'Çözüldü',
            self::Rejected => 'Reddedildi',
        };
    }
}
