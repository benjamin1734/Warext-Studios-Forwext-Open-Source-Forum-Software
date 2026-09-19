<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

enum ContentManagerOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed], true);
    }
}
