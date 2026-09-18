<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Moderation\Report\ReportGroup;
use Forwext\Core\Moderation\Report\ReportRepository;

final readonly class ReportWorkspaceSource implements ModerationWorkspaceSource
{
    public function __construct(
        private QueryExecutor $database,
        private ReportRepository $reports,
    ) {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Reports;
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_report_groups` WHERE `status` IN ('open','in_review')",
        ));
    }

    public function items(int $limit): array
    {
        return array_map($this->item(...), $this->reports->activeGroups($limit));
    }

    private function item(ReportGroup $group): ModerationWorkspaceItem
    {
        $title = strlen($group->targetTitle) <= 240
            ? $group->targetTitle
            : $group->targetType . ' · ' . $group->targetId->value();
        $summary = 'Neden: ' . $group->reasonLabel . ' · Rapor: ' . $group->reportCount;
        if ($group->assignedModeratorUserId !== null) {
            $summary .= ' · Atanan: ' . $group->assignedModeratorUserId->value();
        }

        return new ModerationWorkspaceItem(
            ModerationWorkspaceSection::Reports,
            'report.group',
            $group->groupId->value(),
            $title,
            $group->status->value,
            $group->updatedAt,
            $summary,
            '/moderation/reports/' . rawurlencode($group->groupId->value()),
        );
    }
}
