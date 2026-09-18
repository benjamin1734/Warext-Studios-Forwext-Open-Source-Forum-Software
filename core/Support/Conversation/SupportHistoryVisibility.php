<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

enum SupportHistoryVisibility: string
{
    case Public = 'public';
    case Staff = 'staff';
}
