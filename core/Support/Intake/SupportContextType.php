<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

enum SupportContextType: string
{
    case Thread = 'thread';
    case Account = 'account';
    case MarketplaceListing = 'marketplace_listing';

    public function label(): string
    {
        return match ($this) {
            self::Thread => 'Konu',
            self::Account => 'Hesap',
            self::MarketplaceListing => 'Marketplace ilanı',
        };
    }
}
