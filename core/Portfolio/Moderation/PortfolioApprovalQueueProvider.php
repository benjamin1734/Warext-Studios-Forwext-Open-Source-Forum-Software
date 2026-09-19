<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Moderation\Approval\ApprovalQueueAction;
use Forwext\Core\Moderation\Approval\ApprovalQueueItem;
use Forwext\Core\Moderation\Approval\ApprovalQueueProvider;
use Forwext\Core\Moderation\Approval\ApprovalQueueSelection;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;

final readonly class PortfolioApprovalQueueProvider implements ApprovalQueueProvider
{
    public const PROJECT = 'portfolio.item';
    public const COMMENT = 'portfolio.comment';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private PermissionGate $gate,
        private SearchIndexChangeStore $searchChanges,
        private AuditRecorder $audit,
    ) {
    }

    public function sourceTypes(): array
    {
        return [self::PROJECT, self::COMMENT];
    }

    public function count(): int
    {
        if (!$this->gate->allows(PermissionKey::fromString('portfolio.manage_all'))) {
            return 0;
        }
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT (SELECT COUNT(*) FROM forwext_portfolio_projects WHERE state='pending') + "
            . "(SELECT COUNT(*) FROM forwext_portfolio_comments WHERE state='pending')",
        ));
    }

    public function latest(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Portfolio approval limit is invalid.');
        }
        if (!$this->gate->allows(PermissionKey::fromString('portfolio.manage_all'))) {
            return [];
        }

        $items = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            "SELECT project_id,title,updated_at_utc FROM forwext_portfolio_projects "
            . "WHERE state='pending' ORDER BY updated_at_utc DESC,project_id DESC LIMIT " . $limit,
        )) as $row) {
            $items[] = new ApprovalQueueItem(
                self::PROJECT,
                EntityId::fromString((string) $row['project_id']),
                (string) $row['title'],
                self::date((string) $row['updated_at_utc']),
                'Onay bekleyen portfolyo projesi',
            );
        }

        foreach ($this->database->fetchAll(new CompiledQuery(
            "SELECT c.comment_id,c.body,c.updated_at_utc,p.title FROM forwext_portfolio_comments c "
            . "INNER JOIN forwext_portfolio_projects p ON p.project_id=c.project_id "
            . "WHERE c.state='pending' ORDER BY c.updated_at_utc DESC,c.comment_id DESC LIMIT " . $limit,
        )) as $row) {
            $body = (string) $row['body'];
            $summary = strlen($body) <= 240 ? $body : substr($body, 0, 237) . '...';
            $items[] = new ApprovalQueueItem(
                self::COMMENT,
                EntityId::fromString((string) $row['comment_id']),
                'Portfolyo yorumu — ' . (string) $row['title'],
                self::date((string) $row['updated_at_utc']),
                $summary,
            );
        }

        usort(
            $items,
            static fn (ApprovalQueueItem $a, ApprovalQueueItem $b): int =>
                [$b->updatedAt->format('U.u'), $b->sourceId->value()]
                <=> [$a->updatedAt->format('U.u'), $a->sourceId->value()],
        );
        return array_slice($items, 0, $limit);
    }

    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->gate->require(PermissionKey::fromString('portfolio.manage_all'));

        foreach ($selections as $selection) {
            if (!$selection instanceof ApprovalQueueSelection) {
                throw new InvalidArgumentException('Portfolio approval selection is invalid.');
            }

            if ($selection->sourceType === self::PROJECT) {
                $to = $action === ApprovalQueueAction::Approve ? 'published' : 'rejected';
                $this->auditMutation(
                    $selection,
                    $action,
                    $reason,
                    $requestId,
                    $at,
                    function () use ($selection, $to): void {
                        $changed = $this->database->execute(new CompiledQuery(
                            "UPDATE forwext_portfolio_projects SET state=:state,updated_at_utc=UTC_TIMESTAMP(6) "
                            . "WHERE project_id=:id AND state='pending'",
                            ['state' => $to, 'id' => $selection->sourceId->value()],
                        ));
                        if ($changed !== 1) {
                            throw new InvalidArgumentException('Portfolio project is no longer pending.');
                        }
                        $this->searchChanges->record(self::PROJECT, $selection->sourceId->value());
                    },
                    $to,
                );
                continue;
            }

            if ($selection->sourceType === self::COMMENT) {
                $to = $action === ApprovalQueueAction::Approve ? 'visible' : 'rejected';
                $this->auditMutation(
                    $selection,
                    $action,
                    $reason,
                    $requestId,
                    $at,
                    function () use ($selection, $to): void {
                        $changed = $this->database->execute(new CompiledQuery(
                            "UPDATE forwext_portfolio_comments SET state=:state,updated_at_utc=UTC_TIMESTAMP(6) "
                            . "WHERE comment_id=:id AND state='pending'",
                            ['state' => $to, 'id' => $selection->sourceId->value()],
                        ));
                        if ($changed !== 1) {
                            throw new InvalidArgumentException('Portfolio comment is no longer pending.');
                        }
                    },
                    $to,
                );
                continue;
            }

            throw new InvalidArgumentException('Portfolio approval source type is not supported.');
        }
    }

    private function auditMutation(
        ApprovalQueueSelection $selection,
        ApprovalQueueAction $action,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
        callable $mutation,
        string $toState,
    ): void {
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Moderation,
            $this->gate->actorId(),
            AuditAction::fromString('portfolio.' . $action->value),
            $selection->sourceType,
            $selection->sourceId->value(),
            null,
            $reason->value(),
            AuditRequestId::fromString($requestId->value()),
            ['state' => 'pending'],
            ['state' => $toState],
            $at,
        );
        $this->audit->mutate($event, $mutation);
    }

    private static function date(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new InvalidArgumentException('Stored portfolio approval timestamp is invalid.');
    }
}
