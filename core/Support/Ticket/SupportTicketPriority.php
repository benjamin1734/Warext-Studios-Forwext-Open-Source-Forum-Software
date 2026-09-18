<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

enum SupportTicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Düşük',
            self::Normal => 'Normal',
            self::High => 'Yüksek',
            self::Urgent => 'Acil',
        };
    }
}
