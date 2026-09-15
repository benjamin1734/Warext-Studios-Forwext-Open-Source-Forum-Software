<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Analyzer;

enum PermissionAnalysisLayerState: string
{
    case NotApplicable = 'not_applicable';
    case NoRule = 'no_rule';
    case Inherited = 'inherited';
    case Allowed = 'allowed';
    case Denied = 'denied';
    case FailClosed = 'fail_closed';
    case NotReached = 'not_reached';
}
