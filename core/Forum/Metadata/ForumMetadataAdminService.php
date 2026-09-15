<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;

final readonly class ForumMetadataAdminService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ForumMetadataRepository $metadata,
        private PermissionGate $gate,
    ) {
    }

    public function savePrefixGroup(PrefixGroup $group): void
    {
        $this->requireAdmin();
        $this->metadata->savePrefixGroup($group);
    }

    public function savePrefix(ThreadPrefix $prefix): void
    {
        $this->requireAdmin();
        $this->metadata->savePrefix($prefix);
    }

    public function saveFieldDefinition(CustomFieldDefinition $definition): void
    {
        $this->requireAdmin();
        $this->metadata->saveFieldDefinition($definition);
    }

    /**
     * @param list<EntityId> $prefixGroupIds
     * @param list<CustomFieldKey> $threadFieldKeys
     */
    public function configureForum(
        EntityId $forumNodeId,
        array $prefixGroupIds,
        array $threadFieldKeys,
        bool $tagsEnabled,
        bool $allowNewTags,
        int $maxTags,
    ): ForumContentConfiguration {
        $this->requireAdmin();
        $this->requireForum($forumNodeId);

        foreach ($threadFieldKeys as $fieldKey) {
            $definition = $this->metadata->fieldDefinition($fieldKey);
            if ($definition === null
                || !$definition->isEnabled()
                || $definition->target() !== CustomFieldTarget::Thread
            ) {
                throw new MetadataOperationException('Only enabled thread custom fields can be assigned to a forum.');
            }
        }

        $configuration = new ForumContentConfiguration(
            $forumNodeId,
            $prefixGroupIds,
            $threadFieldKeys,
            $tagsEnabled,
            $allowNewTags,
            $maxTags,
        );
        $this->metadata->saveConfiguration($configuration);
        return $configuration;
    }

    /**
     * @param array<string, mixed> $rawValues
     * @return array<string, CustomFieldValue>
     */
    public function updateForumFields(EntityId $forumNodeId, array $rawValues): array
    {
        $this->requireAdmin();
        $this->requireForum($forumNodeId);

        $definitions = [];
        foreach ($this->metadata->fieldDefinitions(CustomFieldTarget::Forum) as $definition) {
            if ($definition->isEnabled()) {
                $definitions[$definition->key()->value()] = $definition;
            }
        }
        foreach ($rawValues as $key => $_value) {
            if (!is_string($key) || !isset($definitions[$key])) {
                throw new MetadataOperationException('Submitted forum custom field is unknown or disabled.');
            }
        }

        $values = [];
        foreach ($definitions as $key => $definition) {
            if (!array_key_exists($key, $rawValues)) {
                if ($definition->isRequired()) {
                    throw new MetadataOperationException('A required forum custom field is missing.');
                }
                continue;
            }
            $raw = $rawValues[$key];
            if ($definition->isRequired() && is_string($raw) && trim($raw) === '') {
                throw new MetadataOperationException('A required forum custom field cannot be empty.');
            }
            $values[$key] = $definition->validate($raw);
        }

        $this->metadata->replaceForumFieldValues($forumNodeId, $values);
        return $values;
    }

    private function requireAdmin(): void
    {
        $this->gate->require(PermissionKey::fromString('acp.manage'));
    }

    private function requireForum(EntityId $forumNodeId): void
    {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $node = $hierarchy->find($forumNodeId);
        if ($node === null || $node->type() !== ForumNodeType::Forum) {
            throw new MetadataOperationException('Metadata target is not an available forum.');
        }
    }
}
