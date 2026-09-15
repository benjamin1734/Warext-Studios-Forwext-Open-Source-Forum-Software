<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

enum ProfileMusicModerationStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
