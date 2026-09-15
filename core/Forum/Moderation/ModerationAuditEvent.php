<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ModerationAuditEvent
{
    /**
     * @param array<string, bool|int|float|string|null|list<string>> $before
     * @param array<string, bool|int|float|string|null|list<string>> $after
     */
    public function __construct(
        public EntityId $auditId,
        public EntityId $actorUserId,
        public ModerationAuditAction $action,
        public string $targetType,
        public string $targetId,
        public ?EntityId $forumNodeId,
        public ModerationReasonCode $reasonCode,
        public ModerationRequestId $requestId,
        public array $before,
        public array $after,
        DateTimeImmutable $occurredAt,
    ) {
        UserId::assert($this->actorUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Moderation audit target type is invalid.');
        }
        if ($this->targetId === '' || strlen($this->targetId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $this->targetId) !== 1
        ) {
            throw new InvalidArgumentException('Moderation audit target id is invalid.');
        }
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $occurredAt;

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
