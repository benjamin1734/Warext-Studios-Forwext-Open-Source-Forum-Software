<?php

declare(strict_types=1);

namespace Forwext\App\Web\Report;

use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Forum\Moderation\DatabaseModerationAuditStore;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Post\DatabasePostRepository;
use Forwext\Core\Forum\Thread\DatabaseThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use Forwext\Core\Moderation\Report\DatabaseReportRepository;
use Forwext\Core\Moderation\Report\ForumPostReportableContentResolver;
use Forwext\Core\Moderation\Report\ForumThreadReportableContentResolver;
use Forwext\Core\Moderation\Report\NotificationReportNotifier;
use Forwext\Core\Moderation\Report\ReportService;
use Forwext\Core\Moderation\Report\ReportableContentRegistry;
use Forwext\Core\Notification\DatabaseNotificationRepository;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;

final class ReportServiceFactory
{
    public static function create(
        DatabaseConnection $database,
        PermissionAuthorizer $authorizer,
        PermissionGate $gate,
    ): ReportService {
        $nodes = new DatabaseForumNodeRepository($database);
        $threads = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());
        $posts = new DatabasePostRepository($database);
        $reportables = new ReportableContentRegistry([
            new ForumThreadReportableContentResolver($threads, $nodes, $authorizer),
            new ForumPostReportableContentResolver($posts, $threads, $nodes, $authorizer),
        ]);
        $notificationRegistry = new NotificationRegistry();
        NotificationReportNotifier::registerDefinitions($notificationRegistry);
        $notifier = new NotificationReportNotifier(new NotificationDispatcher(
            $notificationRegistry,
            new DatabaseNotificationRepository($database),
        ));

        return new ReportService(
            $database,
            new DatabaseReportRepository($database),
            $reportables,
            $gate,
            $authorizer,
            new DatabaseModerationAuditStore($database),
            $notifier,
        );
    }
}
