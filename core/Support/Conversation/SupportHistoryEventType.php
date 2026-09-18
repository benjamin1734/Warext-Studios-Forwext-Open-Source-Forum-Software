<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

enum SupportHistoryEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case Escalated = 'escalated';
    case Merged = 'merged';
    case SplitCreated = 'split_created';
    case FirstResponse = 'first_response';
}
