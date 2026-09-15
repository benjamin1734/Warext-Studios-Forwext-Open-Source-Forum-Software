<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

enum ProfileMusicPermission: string
{
    case Use = 'profile.music.use';
    case Upload = 'profile.music.upload';
    case External = 'profile.music.external';
    case Autoplay = 'profile.music.autoplay';
    case Moderate = 'profile.music.moderate';
}
