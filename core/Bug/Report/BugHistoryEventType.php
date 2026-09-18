<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

enum BugHistoryEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case SeverityChanged = 'severity_changed';
    case CategoryChanged = 'category_changed';
    case ReporterInfoAdded = 'reporter_info_added';
    case StaffResponse = 'staff_response';
}
