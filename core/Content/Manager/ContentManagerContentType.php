<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

enum ContentManagerContentType: string
{
    case Thread = 'thread';
    case Post = 'post';
}
