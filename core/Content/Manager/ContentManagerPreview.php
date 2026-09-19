<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

final readonly class ContentManagerPreview
{
    /** @param list<ContentManagerTarget> $targets */
    public function __construct(
        public ContentManagerAction $action,
        public array $targets,
        public ?string $targetForumNodeId = null,
        public bool $truncated = false,
    ) {
    }

    public function total(): int
    {
        return count($this->targets);
    }

    /** @return array<string,int> */
    public function countsByType(): array
    {
        $counts = ['thread'=>0,'post'=>0];
        foreach ($this->targets as $target) {
            ++$counts[$target->type->value];
        }
        return $counts;
    }
}
