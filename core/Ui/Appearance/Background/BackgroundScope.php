<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

enum BackgroundScope: string
{
    case Site = 'site';
    case Header = 'header';
    case Category = 'category';
    case Profile = 'profile';

    public function requiresEntityId(): bool
    {
        return $this === self::Category || $this === self::Profile;
    }
}
