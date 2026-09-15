<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use InvalidArgumentException;

final class ForumContentConfiguration
{
    /** @var list<EntityId> */
    private array $prefixGroupIds;
    /** @var list<CustomFieldKey> */
    private array $threadFieldKeys;

    /**
     * @param list<EntityId> $prefixGroupIds
     * @param list<CustomFieldKey> $threadFieldKeys
     */
    public function __construct(
        private readonly EntityId $forumNodeId,
        array $prefixGroupIds = [],
        array $threadFieldKeys = [],
        private readonly bool $tagsEnabled = true,
        private readonly bool $allowNewTags = true,
        private readonly int $maxTags = 5,
    ) {
        ForumNodeId::assert($this->forumNodeId);
        if ($this->maxTags < 0 || $this->maxTags > 20) {
            throw new InvalidArgumentException('Forum maximum tag count must be between 0 and 20.');
        }
        if (!$this->tagsEnabled && ($this->allowNewTags || $this->maxTags !== 0)) {
            throw new InvalidArgumentException('Disabled forum tags must also disable creation and use a zero tag limit.');
        }

        $groups = [];
        foreach ($prefixGroupIds as $groupId) {
            MetadataId::assert($groupId);
            $groups[$groupId->value()] = $groupId;
        }
        $this->prefixGroupIds = array_values($groups);

        $fields = [];
        foreach ($threadFieldKeys as $fieldKey) {
            $fields[$fieldKey->value()] = $fieldKey;
        }
        $this->threadFieldKeys = array_values($fields);
    }

    public function forumNodeId(): EntityId
    {
        return $this->forumNodeId;
    }

    /** @return list<EntityId> */
    public function prefixGroupIds(): array
    {
        return $this->prefixGroupIds;
    }

    /** @return list<CustomFieldKey> */
    public function threadFieldKeys(): array
    {
        return $this->threadFieldKeys;
    }

    public function tagsEnabled(): bool
    {
        return $this->tagsEnabled;
    }

    public function allowNewTags(): bool
    {
        return $this->allowNewTags;
    }

    public function maxTags(): int
    {
        return $this->maxTags;
    }
}
