<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingRequester = 'waiting_requester';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isActive(): bool
    {
        return $this !== self::Resolved && $this !== self::Closed;
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Open => in_array($next, [
                self::InProgress,
                self::WaitingRequester,
                self::Resolved,
                self::Closed,
            ], true),
            self::InProgress => in_array($next, [
                self::Open,
                self::WaitingRequester,
                self::Resolved,
                self::Closed,
            ], true),
            self::WaitingRequester => in_array($next, [
                self::Open,
                self::InProgress,
                self::Resolved,
                self::Closed,
            ], true),
            self::Resolved => in_array($next, [self::Open, self::Closed], true),
            self::Closed => $next === self::Open,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::InProgress => 'İşlemde',
            self::WaitingRequester => 'Kullanıcı bekleniyor',
            self::Resolved => 'Çözüldü',
            self::Closed => 'Kapalı',
        };
    }
}
