<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

enum BugReportStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected, self::Duplicate], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return match ($this) {
            self::New => in_array($next, [
                self::InReview,
                self::Resolved,
                self::Rejected,
                self::Duplicate,
            ], true),
            self::InReview => in_array($next, [
                self::New,
                self::Resolved,
                self::Rejected,
                self::Duplicate,
            ], true),
            self::Resolved,
            self::Rejected,
            self::Duplicate => $next === self::New,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'Yeni',
            self::InReview => 'İncelemede',
            self::Resolved => 'Çözüldü',
            self::Rejected => 'Reddedildi',
            self::Duplicate => 'Tekrarlı',
        };
    }
}
