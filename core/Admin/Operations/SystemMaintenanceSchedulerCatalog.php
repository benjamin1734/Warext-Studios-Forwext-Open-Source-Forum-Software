<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use Forwext\Core\Analytics\AnalyticsMaintenanceTasks;
use Forwext\Core\Forum\Attachment\AttachmentMaintenanceTasks;
use Forwext\Core\Forum\Freshness\ThreadFreshnessMaintenanceTasks;
use Forwext\Core\Giveaway\GiveawayMaintenanceTasks;
use Forwext\Core\Moderation\Abuse\AbuseMaintenanceTasks;
use Forwext\Core\Promotion\PromotionMaintenanceTasks;
use Forwext\Core\Referral\ReferralMaintenanceTasks;
use Forwext\Core\Reward\RewardMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchIndexMaintenanceTasks;
use Forwext\Core\Trophy\TrophyMaintenanceTasks;

final class SystemMaintenanceSchedulerCatalog
{
    public static function coreDefaults(): SchedulerRegistry
    {
        $registry = new SchedulerRegistry();
        AttachmentMaintenanceTasks::register($registry);
        SearchIndexMaintenanceTasks::register($registry);
        ThreadFreshnessMaintenanceTasks::register($registry);
        GiveawayMaintenanceTasks::register($registry);
        ReferralMaintenanceTasks::register($registry);
        AbuseMaintenanceTasks::register($registry);
        AnalyticsMaintenanceTasks::register($registry);
        RewardMaintenanceTasks::register($registry);
        PromotionMaintenanceTasks::register($registry);
        TrophyMaintenanceTasks::register($registry);

        return $registry;
    }
}
