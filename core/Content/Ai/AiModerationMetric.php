<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AiModerationMetric
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $metricId,
        public string $contentFingerprint,
        public ?EntityId $forumNodeId,
        public string $providerKey,
        public string $model,
        public string $promptVersion,
        public bool $redacted,
        public AiModerationUsage $usage,
        DateTimeImmutable $createdAt,
    ) {
        AiModerationFingerprint::assert($this->contentFingerprint);
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
