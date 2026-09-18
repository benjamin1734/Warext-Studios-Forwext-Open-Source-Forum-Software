<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class OversightEntry
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public int $sequence,
        public EntityId $sourceAuditId,
        public EntityId $actorUserId,
        public string $action,
        public string $targetType,
        public string $targetId,
        public string $requestId,
        public string $payloadJson,
        public string $payloadHash,
        public string $previousHash,
        public string $chainHash,
        DateTimeImmutable $occurredAt,
    ) {
        if ($this->sequence < 1) {
            throw new InvalidArgumentException('Oversight sequence must be positive.');
        }
        UserId::assert($this->actorUserId);
        foreach ([$this->payloadHash, $this->previousHash, $this->chainHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException('Oversight chain hash is invalid.');
            }
        }
        if ($this->action === '' || strlen($this->action) > 96) {
            throw new InvalidArgumentException('Oversight action is invalid.');
        }
        if ($this->targetType === '' || strlen($this->targetType) > 32
            || $this->targetId === '' || strlen($this->targetId) > 191
        ) {
            throw new InvalidArgumentException('Oversight target is invalid.');
        }
        if ($this->requestId === '' || strlen($this->requestId) > 100) {
            throw new InvalidArgumentException('Oversight request id is invalid.');
        }
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
