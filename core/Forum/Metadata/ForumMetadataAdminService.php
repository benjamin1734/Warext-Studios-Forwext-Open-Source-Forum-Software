<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
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
        private AuditRecorder $audit,
    ) {
    }

    public function savePrefixGroup(
        PrefixGroup $group,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireAdmin();
        $event = $this->event(
            'forum.metadata.prefix_group.save',
            'forum.prefix_group',
            $group->id()->value(),
            [],
            self::prefixGroupSnapshot($group),
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($group): null {
            $this->metadata->savePrefixGroup($group);
            return null;
        });
    }

    public function savePrefix(
        ThreadPrefix $prefix,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireAdmin();
        $before = $this->metadata->findPrefix($prefix->id());
        $event = $this->event(
            'forum.metadata.prefix.save',
            'forum.thread_prefix',
            $prefix->id()->value(),
            $before === null ? [] : self::prefixSnapshot($before),
            self::prefixSnapshot($prefix),
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($prefix): null {
            $this->metadata->savePrefix($prefix);
            return null;
        });
    }

    public function saveFieldDefinition(
        CustomFieldDefinition $definition,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): void {
        $this->requireAdmin();
        $before = $this->metadata->fieldDefinition($definition->key());
        $event = $this->event(
            'forum.metadata.field_definition.save',
            'forum.custom_field',
            $definition->key()->value(),
            $before === null ? [] : self::fieldDefinitionSnapshot($before),
            self::fieldDefinitionSnapshot($definition),
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($definition): null {
            $this->metadata->saveFieldDefinition($definition);
            return null;
        });
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
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
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

        $before = $this->metadata->configuration($forumNodeId);
        $configuration = new ForumContentConfiguration(
            $forumNodeId,
            $prefixGroupIds,
            $threadFieldKeys,
            $tagsEnabled,
            $allowNewTags,
            $maxTags,
        );
        $event = $this->event(
            'forum.metadata.configuration.save',
            'forum.metadata',
            $forumNodeId->value(),
            self::configurationSnapshot($before),
            self::configurationSnapshot($configuration),
            $requestId,
            $at,
        );
        return $this->audit->mutate($event, function () use ($configuration): ForumContentConfiguration {
            $this->metadata->saveConfiguration($configuration);
            return $configuration;
        });
    }

    /**
     * @param array<string, mixed> $rawValues
     * @return array<string, CustomFieldValue>
     */
    public function updateForumFields(
        EntityId $forumNodeId,
        array $rawValues,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): array
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

        $before = $this->metadata->forumFieldValues($forumNodeId);
        $event = $this->event(
            'forum.metadata.forum_fields.save',
            'forum.fields',
            $forumNodeId->value(),
            self::fieldValuesSnapshot($before),
            self::fieldValuesSnapshot($values),
            $requestId,
            $at,
        );
        return $this->audit->mutate($event, function () use ($forumNodeId, $values): array {
            $this->metadata->replaceForumFieldValues($forumNodeId, $values);
            return $values;
        });
    }

    /** @param array<string|int,mixed> $before @param array<string|int,mixed> $after */
    private function event(
        string $action,
        string $targetType,
        string $targetId,
        array $before,
        array $after,
        ?AuditRequestId $requestId,
        ?DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $this->gate->actorId(),
            AuditAction::fromString($action),
            $targetType,
            $targetId,
            null,
            null,
            $requestId ?? AuditRequestId::generate(),
            $before,
            $after,
            ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /** @return array<string,bool|int|string|null> */
    private static function prefixGroupSnapshot(PrefixGroup $group): array
    {
        return [
            'name' => $group->name(),
            'sort_order' => $group->sortOrder(),
            'enabled' => $group->isEnabled(),
        ];
    }

    /** @return array<string,bool|int|string|null> */
    private static function prefixSnapshot(ThreadPrefix $prefix): array
    {
        return [
            'group_id' => $prefix->groupId()->value(),
            'name' => $prefix->name(),
            'sort_order' => $prefix->sortOrder(),
            'enabled' => $prefix->isEnabled(),
        ];
    }

    /** @return array<string,mixed> */
    private static function fieldDefinitionSnapshot(CustomFieldDefinition $definition): array
    {
        return [
            'target' => $definition->target()->value,
            'label' => $definition->label(),
            'type' => $definition->type()->value,
            'required' => $definition->isRequired(),
            'minimum' => $definition->minimum(),
            'maximum' => $definition->maximum(),
            'choices' => $definition->choices(),
            'sort_order' => $definition->sortOrder(),
            'enabled' => $definition->isEnabled(),
        ];
    }

    /** @return array<string,mixed> */
    private static function configurationSnapshot(ForumContentConfiguration $configuration): array
    {
        return [
            'prefix_group_ids' => array_map(
                static fn (EntityId $id): string => $id->value(),
                $configuration->prefixGroupIds(),
            ),
            'thread_field_keys' => array_map(
                static fn (CustomFieldKey $key): string => $key->value(),
                $configuration->threadFieldKeys(),
            ),
            'tags_enabled' => $configuration->tagsEnabled(),
            'allow_new_tags' => $configuration->allowNewTags(),
            'max_tags' => $configuration->maxTags(),
        ];
    }

    /** @param array<string,CustomFieldValue> $values @return array<string,mixed> */
    private static function fieldValuesSnapshot(array $values): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            $snapshot[$key] = [
                'type' => $value->type->value,
                'value' => $value->value,
            ];
        }
        ksort($snapshot, SORT_STRING);
        return $snapshot;
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
