<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

enum SupportMessageRole: string
{
    case Requester = 'requester';
    case Staff = 'staff';
}
