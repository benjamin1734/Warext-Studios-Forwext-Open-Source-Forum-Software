<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class MentionSuggestion
{
    public function __construct(
        public EntityId $userId,
        public string $username,
    ) {
    }
}
