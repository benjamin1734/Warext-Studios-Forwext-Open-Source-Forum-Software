<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

enum ProfileMusicModerationAction: string
{
    case Block = 'block';
    case Unblock = 'unblock';
}
