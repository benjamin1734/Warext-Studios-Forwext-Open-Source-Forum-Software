<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Dashboard;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AdminActionQueueService
{
    public function __construct(
        private DatabaseConnection $database,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<AdminActionQueueItem> */
    public function forActor(EntityId $actor): array
    {
        $items = [];

        if ($this->allows($actor, 'support.ticket.view_all')) {
            $items[] = new AdminActionQueueItem(
                'support-active',
                'Destek bekliyor',
                'Açık veya personel üzerinde işlemde olan destek talepleri.',
                'admin.support',
                $this->count(
                    "SELECT COUNT(*) FROM forwext_support_tickets WHERE status IN ('open','in_progress')",
                ),
            );
        }

        if ($this->allows($actor, 'bug.report.view_all')) {
            $items[] = new AdminActionQueueItem(
                'bugs-active',
                'Hata incelemesi',
                'Yeni veya inceleme durumundaki hata bildirimleri.',
                'admin.bugs',
                $this->count(
                    "SELECT COUNT(*) FROM forwext_bug_reports WHERE status IN ('new','in_review')",
                ),
            );
        }

        if ($this->allows($actor, 'moderation.access')) {
            $items[] = new AdminActionQueueItem(
                'reports-active',
                'Moderasyon raporları',
                'Açık veya incelenmekte olan içerik rapor grupları.',
                'admin.moderation',
                $this->count(
                    "SELECT COUNT(*) FROM forwext_report_groups WHERE status IN ('open','in_review')",
                ),
            );
            $items[] = new AdminActionQueueItem(
                'moderation-tasks-active',
                'Moderasyon görevleri',
                'Açık veya işlemde olan personel moderasyon görevleri.',
                'admin.moderation',
                $this->count(
                    "SELECT COUNT(*) FROM forwext_moderation_tasks WHERE status IN ('open','in_progress')",
                ),
            );
        }

        return $items;
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    private function count(string $sql): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery($sql)));
    }
}
