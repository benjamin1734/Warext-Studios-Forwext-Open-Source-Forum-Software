<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReferralLink
{
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $expiresAt;

    public function __construct(
        public EntityId $linkId,
        public EntityId $campaignId,
        public EntityId $ownerUserId,
        public string $code,
        ?DateTimeImmutable $expiresAt,
        public bool $disabled,
        DateTimeImmutable $createdAt,
    ) {
        UserId::assert($this->ownerUserId);
        if (preg_match('/^[a-f0-9]{32}$/D', $this->linkId->value()) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->campaignId->value()) !== 1
            || preg_match('/^[A-Za-z0-9_-]{24,64}$/D', $this->code) !== 1
        ) {
            throw new InvalidArgumentException('Referral link is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->expiresAt = $expiresAt?->setTimezone($utc);
        if ($this->expiresAt !== null && $this->expiresAt <= $this->createdAt) {
            throw new InvalidArgumentException('Referral link expiry is invalid.');
        }
    }

    public static function generateCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function isAvailable(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return !$this->disabled && ($this->expiresAt === null || $at < $this->expiresAt);
    }
}
