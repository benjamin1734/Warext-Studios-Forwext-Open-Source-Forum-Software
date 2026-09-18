<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use InvalidArgumentException;

final readonly class ReportService
{
    private const CREATE_PERMISSION = 'report.create';
    private const ACCESS_PERMISSION = 'moderation.access';
    private const MANAGE_PERMISSION = 'moderation.manage';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private ReportRepository $reports,
        private ReportableContentRegistry $reportables,
        private PermissionGate $gate,
        private PermissionAuthorizer $authorizer,
        private ModerationAuditStore $audit,
        private ReportNotifier $notifier,
    ) {
    }

    /** @return list<ReportReason> */
    public function reasons(): array
    {
        $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        return $this->reports->activeReasons();
    }

    public function reportable(string $targetType, EntityId $targetId): ReportableContent
    {
        $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        return $this->reportables->resolve($targetType, $this->gate->actorId(), $targetId)
            ?? throw new ReportTargetUnavailableException('Report target is unavailable to this actor.');
    }

    public function submit(
        string $targetType,
        EntityId $targetId,
        string $reasonKey,
        string $detail = '',
        ?DateTimeImmutable $now = null,
    ): ReportReceipt {
        $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        $detail = trim($detail);
        if (strlen($detail) > 2000) {
            throw new InvalidArgumentException('Report detail cannot exceed 2000 UTF-8 bytes.');
        }
        $reason = $this->reports->reason(strtolower(trim($reasonKey)));
        if ($reason === null || !$reason->active) {
            throw new InvalidArgumentException('Report reason is unavailable.');
        }
        $content = $this->reportables->resolve($targetType, $this->gate->actorId(), $targetId)
            ?? throw new ReportTargetUnavailableException('Report target is unavailable to this actor.');
        $now = self::utc($now);

        return $this->database->transaction(function () use ($content, $reason, $detail, $now): ReportReceipt {
            $match = $this->reports->findOrCreateActiveGroup($content, $reason, $now);
            $receipt = $this->reports->addSubmission(
                $match->group->groupId,
                $this->gate->actorId(),
                $detail,
                $match->created,
                $now,
            );
            if ($receipt->created) {
                $this->notifier->received($this->gate->actorId(), $receipt, $content);
            }
            return $receipt;
        });
    }

    /** @return list<ReportSubmissionSummary> */
    public function ownReports(int $limit = 50): array
    {
        return $this->reports->forReporter($this->gate->actorId(), $limit);
    }

    /** @return list<ReportGroup> */
    public function activeGroups(int $limit = 50): array
    {
        $this->gate->require(PermissionKey::fromString(self::ACCESS_PERMISSION));
        return $this->reports->activeGroups($limit);
    }

    public function group(EntityId $groupId): ReportGroup
    {
        $this->gate->require(PermissionKey::fromString(self::ACCESS_PERMISSION));
        return $this->reports->findGroup($groupId)
            ?? throw new ReportGroupNotFoundException('Report group was not found.');
    }

    /** @return list<ReportComment> */
    public function comments(EntityId $groupId, int $limit = 100): array
    {
        $this->group($groupId);
        return $this->reports->comments($groupId, $limit);
    }

    /** @return list<ReportSubmission> */
    public function submissions(EntityId $groupId, int $limit = 100): array
    {
        $this->group($groupId);
        return $this->reports->submissions($groupId, $limit);
    }

    public function assign(EntityId $groupId, ?EntityId $moderatorUserId, ?DateTimeImmutable $now = null): ReportGroup
    {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));
        if ($moderatorUserId !== null
            && !$this->authorizer->allows($moderatorUserId, PermissionKey::fromString(self::ACCESS_PERMISSION))
        ) {
            throw new InvalidArgumentException('Report assignee does not have moderation workspace access.');
        }
        $before = $this->reports->findGroup($groupId)
            ?? throw new ReportGroupNotFoundException('Report group was not found.');
        if (!$before->status->isActive()) {
            throw new InvalidArgumentException('Closed report groups cannot be reassigned.');
        }
        if ($before->assignedModeratorUserId?->value() === $moderatorUserId?->value()) {
            return $before;
        }
        $now = self::utc($now);

        return $this->database->transaction(function () use ($before, $groupId, $moderatorUserId, $now): ReportGroup {
            $after = $this->reports->assign($groupId, $moderatorUserId, $now);
            $this->appendAudit(
                ModerationAuditAction::ReportAssign,
                $groupId,
                'report.assignment',
                ['assigned_moderator_user_id' => $before->assignedModeratorUserId?->value()],
                ['assigned_moderator_user_id' => $after->assignedModeratorUserId?->value()],
                $now,
            );
            if ($moderatorUserId !== null) {
                $this->notifier->assigned($moderatorUserId, $after);
            }
            return $after;
        });
    }

    public function setStatus(EntityId $groupId, ReportStatus $status, ?DateTimeImmutable $now = null): ReportGroup
    {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));
        $before = $this->reports->findGroup($groupId)
            ?? throw new ReportGroupNotFoundException('Report group was not found.');
        if ($before->status === $status) {
            return $before;
        }
        if (!$before->status->isActive()) {
            throw new InvalidArgumentException('Closed report groups cannot change status.');
        }
        $now = self::utc($now);

        return $this->database->transaction(function () use ($before, $groupId, $status, $now): ReportGroup {
            $after = $this->reports->updateStatus($groupId, $status, $now);
            $this->appendAudit(
                ModerationAuditAction::ReportStatus,
                $groupId,
                'report.status',
                ['status' => $before->status->value],
                ['status' => $after->status->value],
                $now,
            );
            $this->notifier->statusChanged($this->reports->reporterIds($groupId), $after);
            return $after;
        });
    }

    public function addModeratorComment(EntityId $groupId, string $body, ?DateTimeImmutable $now = null): ReportComment
    {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));
        $this->reports->findGroup($groupId)
            ?? throw new ReportGroupNotFoundException('Report group was not found.');
        $body = trim($body);
        if ($body === '' || strlen($body) > 4000) {
            throw new InvalidArgumentException('Moderator comment must contain 1-4000 UTF-8 bytes.');
        }
        $now = self::utc($now);
        $comment = new ReportComment(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $groupId,
            $this->gate->actorId(),
            $body,
            $now,
        );

        $this->database->transaction(function () use ($comment, $now): void {
            $this->reports->addComment($comment);
            $this->appendAudit(
                ModerationAuditAction::ReportComment,
                $comment->groupId,
                'report.comment',
                [],
                ['comment_id' => $comment->commentId->value(), 'body_length' => strlen($comment->body)],
                $now,
            );
        });
        return $comment;
    }

    /**
     * @param array<string, bool|int|float|string|null|list<string>> $before
     * @param array<string, bool|int|float|string|null|list<string>> $after
     */
    private function appendAudit(
        ModerationAuditAction $action,
        EntityId $groupId,
        string $reasonCode,
        array $before,
        array $after,
        DateTimeImmutable $now,
    ): void {
        $this->audit->append(new ModerationAuditEvent(
            ModerationAuditEvent::generateId(),
            $this->gate->actorId(),
            $action,
            'report_group',
            $groupId->value(),
            null,
            ModerationReasonCode::fromString($reasonCode),
            ModerationRequestId::generate(),
            $before,
            $after,
            $now,
        ));
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
