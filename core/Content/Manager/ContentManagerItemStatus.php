<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

enum ContentManagerItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function terminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Skipped, self::Failed], true);
    }
}
