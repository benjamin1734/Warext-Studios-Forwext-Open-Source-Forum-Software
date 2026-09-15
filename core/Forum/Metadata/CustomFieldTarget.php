<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

enum CustomFieldTarget: string
{
    case Thread = 'thread';
    case Forum = 'forum';
}
