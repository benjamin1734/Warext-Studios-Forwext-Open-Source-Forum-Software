<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

enum MigrationStatus: string
{
    case Running = 'running';
    case Applied = 'applied';
    case Failed = 'failed';
}
