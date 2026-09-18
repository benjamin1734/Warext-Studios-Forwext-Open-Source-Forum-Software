<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

enum SupportMessageVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
