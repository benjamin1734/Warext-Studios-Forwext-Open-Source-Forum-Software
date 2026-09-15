<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

enum ProfileMusicSourceType: string
{
    case Upload = 'upload';
    case External = 'external';
}
