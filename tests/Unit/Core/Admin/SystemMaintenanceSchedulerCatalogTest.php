<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Operations\SystemMaintenanceSchedulerCatalog;
use PHPUnit\Framework\TestCase;

final class SystemMaintenanceSchedulerCatalogTest extends TestCase
{
    public function testCatalogExposesRegisteredCoreMaintenanceTasksWithoutDuplicates(): void
    {
        $tasks = SystemMaintenanceSchedulerCatalog::coreDefaults()->all();
        $names = array_map(static fn ($task): string => $task->name, $tasks);

        self::assertCount(10, $tasks);
        self::assertCount(count($names), array_unique($names));
        self::assertSame([
            'forum.attachments.cleanup',
            'search.index.drain',
            'thread.freshness.maintain',
            'giveaway.lifecycle',
            'referral.qualify',
            'moderation.abuse.retention_cleanup',
            'analytics.retention.prune',
            'reward.retry',
            'promotion.evaluate',
            'trophy.evaluate',
        ], $names);
    }
}
