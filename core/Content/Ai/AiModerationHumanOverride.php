<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class AiModerationHumanOverride
{
    public string $reason;
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $expiresAt;

    public function __construct(
        public string $contentFingerprint,
        public AiModerationAction $action,
        public ?EntityId $actorUserId,
        string $reason,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt = null,
    ) {
        AiModerationFingerprint::assert($this->contentFingerprint);
        if ($this->actorUserId !== null) {
            UserId::assert($this->actorUserId);
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('AI moderation override reason is invalid.');
        }
        $this->reason = $reason;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
        if ($this->expiresAt !== null && $this->expiresAt <= $this->createdAt) {
            throw new InvalidArgumentException('AI moderation override expiry must be after creation.');
        }
    }

    public function isActive(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->expiresAt === null || $this->expiresAt > $at;
    }
}
