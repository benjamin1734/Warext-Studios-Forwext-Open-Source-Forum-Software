<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

enum BugReportMessageRole: string
{
    case Reporter = 'reporter';
    case Staff = 'staff';
}
