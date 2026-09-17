<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

enum ModerationTaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
}
