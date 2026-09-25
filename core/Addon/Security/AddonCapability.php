<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

enum AddonCapability: string
{
    case Database = 'database';
    case Filesystem = 'filesystem';
    case OutboundNetwork = 'outbound_network';
    case BackgroundJobs = 'background_jobs';
    case ScheduledTasks = 'scheduled_tasks';
    case AdminUi = 'admin_ui';
    case UserUi = 'user_ui';
    case ContentExtension = 'content_extension';
    case Permissions = 'permissions';
    case Webhooks = 'webhooks';
}
