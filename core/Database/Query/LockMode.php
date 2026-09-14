<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

enum LockMode: string
{
    case None = 'none';
    case ForUpdate = 'for_update';
    case Shared = 'shared';
}
