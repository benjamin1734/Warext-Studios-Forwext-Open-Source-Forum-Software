<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

enum FirstPartyModuleScope: string
{
    case Global = 'global';
    case Forum = 'forum';
    case Group = 'group';
    case Thread = 'thread';
    case Post = 'post';

    public function needsTarget(): bool
    {
        return $this !== self::Global;
    }
}
