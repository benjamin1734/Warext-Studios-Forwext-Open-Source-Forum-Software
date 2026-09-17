<?php

declare(strict_types=1);

namespace Forwext\Core\Presence;

enum PresenceVisibility: string
{
    case Hidden = 'hidden';
    case Members = 'members';
    case Public = 'public';
}
