<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

enum ComparisonOperator: string
{
    case Equal = '=';
    case NotEqual = '<>';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
}
