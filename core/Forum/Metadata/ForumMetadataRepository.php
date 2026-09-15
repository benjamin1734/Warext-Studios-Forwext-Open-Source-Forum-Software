<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Entity\EntityId;

interface ForumMetadataRepository
{
    public function savePrefixGroup(PrefixGroup $group): void;

    public function savePrefix(ThreadPrefix $prefix): void;

    public function findPrefix(EntityId $prefixId): ?ThreadPrefix;

    /** @return list<ThreadPrefix> */
    public function prefixesForForum(EntityId $forumNodeId): array;

    public function configuration(EntityId $forumNodeId): ForumContentConfiguration;

    public function saveConfiguration(ForumContentConfiguration $configuration): void;

    public function saveFieldDefinition(CustomFieldDefinition $definition): void;

    public function fieldDefinition(CustomFieldKey $key): ?CustomFieldDefinition;

    /** @return list<CustomFieldDefinition> */
    public function fieldDefinitions(CustomFieldTarget $target): array;

    /** @return list<Tag> */
    public function autocompleteTags(string $query, int $limit = 10): array;

    public function threadMetadata(EntityId $threadId): ThreadMetadata;

    public function replaceThreadMetadata(
        EntityId $threadId,
        ThreadMetadata $metadata,
        bool $allowNewTags,
    ): void;

    /** @return array<string, CustomFieldValue> */
    public function forumFieldValues(EntityId $forumNodeId): array;

    /** @param array<string, CustomFieldValue> $values */
    public function replaceForumFieldValues(EntityId $forumNodeId, array $values): void;
}
