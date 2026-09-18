<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ContentPipelinePersisted
{
    public function __construct(
        public object $value,
        public string $targetType,
        public EntityId $targetId,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Content pipeline persisted target type is invalid.');
        }
    }
}
