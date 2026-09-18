<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

enum SupportTicketRelationType: string
{
    case MergedInto = 'merged_into';
    case SplitFrom = 'split_from';
}
