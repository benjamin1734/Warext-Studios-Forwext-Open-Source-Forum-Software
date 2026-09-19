<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class ContentManagerOperationItem
{
    public function __construct(
        public EntityId $operationId,
        public ContentManagerContentType $type,
        public EntityId $contentId,
        public EntityId $forumNodeId,
        public ContentManagerItemStatus $status,
        public ?string $failureCode = null,
    ) {
    }
}
