<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Audit\DatabaseAuditEventStore;
use Forwext\Core\Audit\SensitiveAuditRedactor;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Moderation\Oversight\DatabaseModerationOversightStore;
use Forwext\Core\Moderation\Oversight\ModerationOversightStore;
use RuntimeException;

final readonly class DatabaseModerationAuditStore implements ModerationAuditStore
{
    private DatabaseAuditEventStore $central;
    private ModerationOversightStore $oversight;

    public function __construct(
        private TransactionalQueryExecutor $database,
        ?ModerationOversightStore $oversight = null,
    ) {
        $this->central = new DatabaseAuditEventStore($this->database, new SensitiveAuditRedactor());
        $this->oversight = $oversight ?? new DatabaseModerationOversightStore($this->database);
    }

    public function append(ModerationAuditEvent $event): void
    {
        $this->central->append(new AuditEvent(
            $event->auditId,
            AuditScope::Moderation,
            $event->actorUserId,
            AuditAction::fromString($event->action->value),
            $event->targetType,
            $event->targetId,
            $event->forumNodeId,
            $event->reasonCode->value(),
            AuditRequestId::fromString($event->requestId->value()),
            $event->before,
            $event->after,
            $event->occurredAt,
        ));
        $this->oversight->append($event);
    }

    public function recentForTarget(string $targetType, string $targetId, int $limit = 100): array
    {
        return array_map(
            static function (AuditEvent $event): ModerationAuditEvent {
                $action = ModerationAuditAction::tryFrom($event->action->value());
                if ($action === null || $event->reasonCode === null) {
                    throw new RuntimeException('Central audit moderation event is not compatible with moderation audit contract.');
                }
                return new ModerationAuditEvent(
                    $event->auditId,
                    $event->actorUserId,
                    $action,
                    $event->targetType,
                    $event->targetId,
                    $event->forumNodeId,
                    ModerationReasonCode::fromString($event->reasonCode),
                    ModerationRequestId::fromString($event->requestId->value()),
                    $event->before,
                    $event->after,
                    $event->occurredAt,
                );
            },
            $this->central->recentForTarget($targetType, $targetId, $limit, AuditScope::Moderation),
        );
    }
}
