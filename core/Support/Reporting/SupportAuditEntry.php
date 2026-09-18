<?php
declare(strict_types=1);
namespace Forwext\Core\Support\Reporting;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
final readonly class SupportAuditEntry {
    public DateTimeImmutable $occurredAt;
    public function __construct(
        public EntityId $auditId,
        public EntityId $actorUserId,
        public string $action,
        public string $targetType,
        public string $targetId,
        public string $requestId,
        DateTimeImmutable $occurredAt,
    ){ $this->occurredAt=$occurredAt->setTimezone(new DateTimeZone('UTC')); }
}
