<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

enum ContentManagerAction: string
{
    case Delete = 'delete';
    case Restore = 'restore';
    case Move = 'move';
    case Approve = 'approve';
    case Reindex = 'reindex';
    case Reprocess = 'reprocess';

    public function requiresTargetForum(): bool
    {
        return $this === self::Move;
    }
}
