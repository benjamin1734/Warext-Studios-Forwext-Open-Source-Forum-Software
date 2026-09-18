<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Report;

use Forwext\Core\Bug\Report\NotificationBugReportNotifier;
use Forwext\Core\Notification\NotificationRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationBugReportNotifierTest extends TestCase
{
    public function testBugNotificationTypesUseSharedRegistry(): void
    {
        $registry = new NotificationRegistry();

        NotificationBugReportNotifier::registerDefinitions($registry);

        self::assertTrue($registry->has(NotificationBugReportNotifier::STAFF_RESPONSE));
        self::assertTrue($registry->has(NotificationBugReportNotifier::STATUS));
        self::assertTrue($registry->has(NotificationBugReportNotifier::REPORTER_INFO));
        self::assertSame('bug', $registry->require(NotificationBugReportNotifier::STAFF_RESPONSE)->categoryKey);
    }
}
