<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

enum BugHistoryVisibility: string
{
    case Public = 'public';
    case Staff = 'staff';
}
