<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

enum ModerationTaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
