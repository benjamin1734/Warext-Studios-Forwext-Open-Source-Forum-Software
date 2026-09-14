<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

enum OrderDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
