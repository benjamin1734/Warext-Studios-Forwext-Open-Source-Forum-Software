<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use InvalidArgumentException;

final readonly class ModerationOversightService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ModerationOversightStore $store,
        private OversightReviewRepository $reviews,
        private ModerationOversightVerifier $verifier,
        private PermissionGate $gate,
    ) {
    }

    public function overview(int $limit = 100, bool $verify = false): OversightOverview
    {
        $this->gate->require(PermissionKey::fromString('audit.view'));
        return new OversightOverview(
            $verify ? $this->verifier->verify() : null,
            $this->store->recent($limit),
            $this->reviews->openCases($limit),
            $this->reviews->openFlags($limit),
        );
    }

    public function openCase(
        EntityId $sourceAuditId,
        string $summary,
        ?DateTimeImmutable $at = null,
    ): OversightReviewCase {
        $this->requireReview();
        $entry = $this->requireIndependentTarget($sourceAuditId);
        $summary = trim($summary);
        if ($summary === '' || strlen($summary) > 1000) {
            throw new InvalidArgumentException('Oversight review summary must contain 1-1000 bytes.');
        }
        $case = new OversightReviewCase(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $entry->sourceAuditId,
            $this->gate->actorId(),
            $summary,
            OversightReviewStatus::Open,
            self::utc($at),
        );
        $this->database->transaction(function () use ($case): void {
            $this->reviews->insertCase($case);
        });
        return $case;
    }

    public function resolveCase(
        EntityId $caseId,
        string $resolution,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireReview();
        $case = $this->reviews->reviewCase($caseId)
            ?? throw new OversightOperationException('Oversight review case was not found.');
        if ($case->status !== OversightReviewStatus::Open) {
            throw new OversightOperationException('Oversight review case is already resolved.');
        }
        $this->requireIndependentTarget($case->sourceAuditId);
        $this->database->transaction(function () use ($caseId, $resolution, $at): void {
            $this->reviews->resolveCase($caseId, $this->gate->actorId(), $resolution, self::utc($at));
        });
    }

    public function flag(
        EntityId $sourceAuditId,
        string $flagType,
        OversightAnomalySeverity $severity,
        string $details,
        ?DateTimeImmutable $at = null,
    ): OversightAnomalyFlag {
        $this->requireReview();
        $entry = $this->requireIndependentTarget($sourceAuditId);
        $flag = new OversightAnomalyFlag(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $entry->sourceAuditId,
            $flagType,
            $severity,
            trim($details),
            self::utc($at),
        );
        $this->database->transaction(function () use ($flag): void {
            $this->reviews->insertFlag($flag);
        });
        return $flag;
    }

    public function resolveFlag(
        EntityId $flagId,
        string $resolution,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireReview();
        $flag = $this->reviews->flag($flagId)
            ?? throw new OversightOperationException('Oversight anomaly flag was not found.');
        if ($flag->isResolved()) {
            throw new OversightOperationException('Oversight anomaly flag is already resolved.');
        }
        $this->requireIndependentTarget($flag->sourceAuditId);
        $this->database->transaction(function () use ($flagId, $resolution, $at): void {
            $this->reviews->resolveFlag($flagId, $this->gate->actorId(), $resolution, self::utc($at));
        });
    }

    private function requireIndependentTarget(EntityId $sourceAuditId): OversightEntry
    {
        $entry = $this->store->findByAuditId($sourceAuditId)
            ?? throw new OversightOperationException('Oversight source audit entry was not found.');
        if ($entry->actorUserId->equals($this->gate->actorId())) {
            throw new PermissionDeniedException('Reviewers cannot review, flag or resolve their own moderation audit entry.');
        }
        return $entry;
    }

    private function requireReview(): void
    {
        $this->gate->require(PermissionKey::fromString('audit.review'));
    }

    private static function utc(?DateTimeImmutable $at): DateTimeImmutable
    {
        return ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
