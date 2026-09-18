<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class AuditEvent
{
    public DateTimeImmutable $occurredAt;

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    public function __construct(
        public EntityId $auditId,
        public AuditScope $scope,
        public EntityId $actorUserId,
        public AuditAction $action,
        public string $targetType,
        public string $targetId,
        public ?EntityId $forumNodeId,
        public ?string $reasonCode,
        public AuditRequestId $requestId,
        public array $before,
        public array $after,
        DateTimeImmutable $occurredAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->auditId->value()) !== 1) {
            throw new InvalidArgumentException('Audit id must be a 128-bit lowercase hexadecimal identifier.');
        }
        UserId::assert($this->actorUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Audit target type is invalid.');
        }
        if ($this->targetId === '' || strlen($this->targetId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $this->targetId) !== 1
        ) {
            throw new InvalidArgumentException('Audit target id is invalid.');
        }
        if ($this->reasonCode !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->reasonCode) !== 1
        ) {
            throw new InvalidArgumentException('Audit reason code is invalid.');
        }
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
