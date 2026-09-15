<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum PollPermission: string
{
    case Create = 'forum.poll.create';
    case Vote = 'forum.poll.vote';
    case ViewResults = 'forum.poll.view_results';
    case ViewVoters = 'forum.poll.view_voters';
    case Manage = 'forum.poll.manage';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
