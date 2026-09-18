<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ReportRepository
{
    /** @return list<ReportReason> */
    public function activeReasons(): array;

    public function reason(string $reasonKey): ?ReportReason;

    public function findOrCreateActiveGroup(
        ReportableContent $content,
        ReportReason $reason,
        DateTimeImmutable $now,
    ): ReportGroupMatch;

    public function addSubmission(
        EntityId $groupId,
        EntityId $reporterUserId,
        string $detail,
        bool $groupCountAlreadyIncludesSubmission,
        DateTimeImmutable $now,
    ): ReportReceipt;

    public function findGroup(EntityId $groupId): ?ReportGroup;

    /** @return list<ReportGroup> */
    public function activeGroups(int $limit = 50): array;

    public function assign(EntityId $groupId, ?EntityId $moderatorUserId, DateTimeImmutable $now): ReportGroup;

    public function updateStatus(EntityId $groupId, ReportStatus $status, DateTimeImmutable $now): ReportGroup;

    public function addComment(ReportComment $comment): void;

    /** @return list<ReportComment> */
    public function comments(EntityId $groupId, int $limit = 100): array;

    /** @return list<ReportSubmission> */
    public function submissions(EntityId $groupId, int $limit = 100): array;

    /** @return list<EntityId> */
    public function reporterIds(EntityId $groupId): array;

    /** @return list<ReportSubmissionSummary> */
    public function forReporter(EntityId $reporterUserId, int $limit = 50): array;
}
