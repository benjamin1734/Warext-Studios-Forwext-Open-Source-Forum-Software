<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AiModerationDecisionRecord
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $decisionId,
        public string $contentFingerprint,
        public string $providerKey,
        public string $model,
        public float $riskScore,
        public AiModerationAction $action,
        public ?string $fallbackReason,
        public bool $humanOverride,
        public ?string $targetType,
        public ?EntityId $targetId,
        DateTimeImmutable $createdAt,
    ) {
        AiModerationFingerprint::assert($this->contentFingerprint);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1
            || $this->model === '' || strlen($this->model) > 191
            || !is_finite($this->riskScore) || $this->riskScore < 0.0 || $this->riskScore > 1.0
        ) {
            throw new InvalidArgumentException('AI moderation decision metadata is invalid.');
        }
        if (($this->targetType === null) !== ($this->targetId === null)) {
            throw new InvalidArgumentException('AI moderation decision target fields must be both set or both null.');
        }
        if ($this->targetType !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->targetType) !== 1
        ) {
            throw new InvalidArgumentException('AI moderation decision target type is invalid.');
        }
        if ($this->fallbackReason !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->fallbackReason) !== 1
        ) {
            throw new InvalidArgumentException('AI moderation decision fallback reason is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
