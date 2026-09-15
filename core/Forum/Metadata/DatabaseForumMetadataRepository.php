<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DatabaseForumMetadataRepository implements ForumMetadataRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function savePrefixGroup(PrefixGroup $group): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_prefix_groups` (`group_id`, `name`, `sort_order`, `enabled`, `updated_at_utc`) '
            . 'VALUES (:group_id, :name, :sort_order, :enabled, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`), '
            . '`enabled` = VALUES(`enabled`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'group_id' => $group->id()->value(),
                'name' => $group->name(),
                'sort_order' => $group->sortOrder(),
                'enabled' => $group->isEnabled(),
            ],
        ));
    }

    public function savePrefix(ThreadPrefix $prefix): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_thread_prefixes` '
            . '(`prefix_id`, `group_id`, `name`, `sort_order`, `enabled`, `updated_at_utc`) '
            . 'VALUES (:prefix_id, :group_id, :name, :sort_order, :enabled, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `group_id` = VALUES(`group_id`), `name` = VALUES(`name`), '
            . '`sort_order` = VALUES(`sort_order`), `enabled` = VALUES(`enabled`), '
            . '`updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'prefix_id' => $prefix->id()->value(),
                'group_id' => $prefix->groupId()->value(),
                'name' => $prefix->name(),
                'sort_order' => $prefix->sortOrder(),
                'enabled' => $prefix->isEnabled(),
            ],
        ));
    }

    public function findPrefix(EntityId $prefixId): ?ThreadPrefix
    {
        MetadataId::assert($prefixId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `prefix_id`, `group_id`, `name`, `sort_order`, `enabled` '
            . 'FROM `forwext_thread_prefixes` WHERE `prefix_id` = :prefix_id LIMIT 1',
            ['prefix_id' => $prefixId->value()],
        ));

        return $row === null ? null : $this->hydratePrefix($row);
    }

    public function prefixesForForum(EntityId $forumNodeId): array
    {
        ForumNodeId::assert($forumNodeId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT p.`prefix_id`, p.`group_id`, p.`name`, p.`sort_order`, p.`enabled` '
            . 'FROM `forwext_thread_prefixes` p '
            . 'INNER JOIN `forwext_prefix_groups` g ON g.`group_id` = p.`group_id` '
            . 'INNER JOIN `forwext_forum_prefix_groups` fpg ON fpg.`group_id` = g.`group_id` '
            . 'WHERE fpg.`forum_node_id` = :forum_node_id AND g.`enabled` = 1 AND p.`enabled` = 1 '
            . 'ORDER BY g.`sort_order`, g.`name`, p.`sort_order`, p.`name`, p.`prefix_id`',
            ['forum_node_id' => $forumNodeId->value()],
        ));

        return array_map($this->hydratePrefix(...), $rows);
    }

    public function configuration(EntityId $forumNodeId): ForumContentConfiguration
    {
        ForumNodeId::assert($forumNodeId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `tags_enabled`, `allow_new_tags`, `max_tags` FROM `forwext_forum_content_config` '
            . 'WHERE `forum_node_id` = :forum_node_id LIMIT 1',
            ['forum_node_id' => $forumNodeId->value()],
        ));
        $groupRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `group_id` FROM `forwext_forum_prefix_groups` '
            . 'WHERE `forum_node_id` = :forum_node_id ORDER BY `group_id`',
            ['forum_node_id' => $forumNodeId->value()],
        ));
        $fieldRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `field_key` FROM `forwext_forum_thread_fields` '
            . 'WHERE `forum_node_id` = :forum_node_id ORDER BY `field_key`',
            ['forum_node_id' => $forumNodeId->value()],
        ));

        $groups = array_map(
            static fn (array $item): EntityId => MetadataId::fromStored((string) $item['group_id']),
            $groupRows,
        );
        $fields = array_map(
            static fn (array $item): CustomFieldKey => CustomFieldKey::fromString((string) $item['field_key']),
            $fieldRows,
        );

        if ($row === null) {
            return new ForumContentConfiguration($forumNodeId, $groups, $fields, false, false, 0);
        }

        return new ForumContentConfiguration(
            $forumNodeId,
            $groups,
            $fields,
            (bool) $row['tags_enabled'],
            (bool) $row['allow_new_tags'],
            (int) $row['max_tags'],
        );
    }

    public function saveConfiguration(ForumContentConfiguration $configuration): void
    {
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($configuration): void {
            $forumId = $configuration->forumNodeId()->value();
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_forum_content_config` '
                . '(`forum_node_id`, `tags_enabled`, `allow_new_tags`, `max_tags`, `updated_at_utc`) '
                . 'VALUES (:forum_node_id, :tags_enabled, :allow_new_tags, :max_tags, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `tags_enabled` = VALUES(`tags_enabled`), '
                . '`allow_new_tags` = VALUES(`allow_new_tags`), `max_tags` = VALUES(`max_tags`), '
                . '`updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'forum_node_id' => $forumId,
                    'tags_enabled' => $configuration->tagsEnabled(),
                    'allow_new_tags' => $configuration->allowNewTags(),
                    'max_tags' => $configuration->maxTags(),
                ],
            ));
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_forum_prefix_groups` WHERE `forum_node_id` = :forum_node_id',
                ['forum_node_id' => $forumId],
            ));
            foreach ($configuration->prefixGroupIds() as $groupId) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_forum_prefix_groups` (`forum_node_id`, `group_id`) '
                    . 'VALUES (:forum_node_id, :group_id)',
                    ['forum_node_id' => $forumId, 'group_id' => $groupId->value()],
                ));
            }
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_forum_thread_fields` WHERE `forum_node_id` = :forum_node_id',
                ['forum_node_id' => $forumId],
            ));
            foreach ($configuration->threadFieldKeys() as $fieldKey) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_forum_thread_fields` (`forum_node_id`, `field_key`) '
                    . 'VALUES (:forum_node_id, :field_key)',
                    ['forum_node_id' => $forumId, 'field_key' => $fieldKey->value()],
                ));
            }
        });
    }

    public function saveFieldDefinition(CustomFieldDefinition $definition): void
    {
        try {
            $choices = json_encode(
                $definition->choices(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Custom field choices could not be encoded.', 0, $exception);
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_custom_fields` '
            . '(`field_key`, `target`, `label`, `value_type`, `required`, `minimum_value`, `maximum_value`, '
            . '`choices_json`, `sort_order`, `enabled`, `updated_at_utc`) '
            . 'VALUES (:field_key, :target, :label, :value_type, :required, :minimum_value, :maximum_value, '
            . ':choices_json, :sort_order, :enabled, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `target` = VALUES(`target`), `label` = VALUES(`label`), '
            . '`value_type` = VALUES(`value_type`), `required` = VALUES(`required`), '
            . '`minimum_value` = VALUES(`minimum_value`), `maximum_value` = VALUES(`maximum_value`), '
            . '`choices_json` = VALUES(`choices_json`), `sort_order` = VALUES(`sort_order`), '
            . '`enabled` = VALUES(`enabled`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'field_key' => $definition->key()->value(),
                'target' => $definition->target()->value,
                'label' => $definition->label(),
                'value_type' => $definition->type()->value,
                'required' => $definition->isRequired(),
                'minimum_value' => $definition->minimum(),
                'maximum_value' => $definition->maximum(),
                'choices_json' => $choices,
                'sort_order' => $definition->sortOrder(),
                'enabled' => $definition->isEnabled(),
            ],
        ));
    }

    public function fieldDefinition(CustomFieldKey $key): ?CustomFieldDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->fieldSelectSql() . ' WHERE `field_key` = :field_key LIMIT 1',
            ['field_key' => $key->value()],
        ));
        return $row === null ? null : $this->hydrateField($row);
    }

    public function fieldDefinitions(CustomFieldTarget $target): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->fieldSelectSql() . ' WHERE `target` = :target '
            . 'ORDER BY `sort_order`, `label`, `field_key`',
            ['target' => $target->value],
        ));
        return array_map($this->hydrateField(...), $rows);
    }

    public function autocompleteTags(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 64 || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Tag autocomplete query or limit is invalid.');
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `tag_id`, `name` FROM `forwext_tags` '
            . "WHERE `name` LIKE :query ESCAPE '\\\\' ORDER BY `name`, `tag_id` LIMIT " . $limit,
            ['query' => $escaped . '%'],
        ));
        return array_map(
            static fn (array $row): Tag => new Tag(
                MetadataId::fromStored((string) $row['tag_id']),
                TagName::fromString((string) $row['name']),
            ),
            $rows,
        );
    }

    public function threadMetadata(EntityId $threadId): ThreadMetadata
    {
        ThreadId::assert($threadId);
        $prefixRow = $this->database->fetchOne(new CompiledQuery(
            'SELECT `prefix_id` FROM `forwext_thread_prefix_assignments` WHERE `thread_id` = :thread_id LIMIT 1',
            ['thread_id' => $threadId->value()],
        ));
        $tagRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.`name` FROM `forwext_tags` t '
            . 'INNER JOIN `forwext_thread_tags` tt ON tt.`tag_id` = t.`tag_id` '
            . 'WHERE tt.`thread_id` = :thread_id ORDER BY t.`name`, t.`tag_id`',
            ['thread_id' => $threadId->value()],
        ));
        $valueRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT v.`field_key`, f.`value_type`, v.`value_json` '
            . 'FROM `forwext_thread_custom_field_values` v '
            . 'INNER JOIN `forwext_custom_fields` f ON f.`field_key` = v.`field_key` '
            . 'WHERE v.`thread_id` = :thread_id ORDER BY v.`field_key`',
            ['thread_id' => $threadId->value()],
        ));

        $tags = array_map(
            static fn (array $row): TagName => TagName::fromString((string) $row['name']),
            $tagRows,
        );
        return new ThreadMetadata(
            $prefixRow === null ? null : MetadataId::fromStored((string) $prefixRow['prefix_id']),
            $tags,
            $this->hydrateValues($valueRows),
        );
    }

    public function replaceThreadMetadata(
        EntityId $threadId,
        ThreadMetadata $metadata,
        bool $allowNewTags,
    ): void {
        ThreadId::assert($threadId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $threadId,
            $metadata,
            $allowNewTags,
        ): void {
            $threadKey = $threadId->value();
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_thread_prefix_assignments` WHERE `thread_id` = :thread_id',
                ['thread_id' => $threadKey],
            ));
            if ($metadata->prefixId() !== null) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_thread_prefix_assignments` (`thread_id`, `prefix_id`) '
                    . 'VALUES (:thread_id, :prefix_id)',
                    ['thread_id' => $threadKey, 'prefix_id' => $metadata->prefixId()->value()],
                ));
            }

            /** @var array<string, EntityId> $resolvedTagIds */
            $resolvedTagIds = [];
            foreach ($metadata->tags() as $tagName) {
                $row = $database->fetchOne(new CompiledQuery(
                    'SELECT `tag_id` FROM `forwext_tags` WHERE `name` = :name LIMIT 1 FOR UPDATE',
                    ['name' => $tagName->value()],
                    true,
                ));
                if ($row === null && !$allowNewTags) {
                    throw new InvalidArgumentException('Forum policy does not allow creating a new tag.');
                }
                if ($row === null) {
                    $candidate = MetadataId::generate();
                    $database->execute(new CompiledQuery(
                        'INSERT INTO `forwext_tags` (`tag_id`, `name`, `created_at_utc`) '
                        . 'VALUES (:tag_id, :name, UTC_TIMESTAMP(6)) '
                        . 'ON DUPLICATE KEY UPDATE `tag_id` = `tag_id`',
                        ['tag_id' => $candidate->value(), 'name' => $tagName->value()],
                    ));
                    $row = $database->fetchOne(new CompiledQuery(
                        'SELECT `tag_id` FROM `forwext_tags` WHERE `name` = :name LIMIT 1',
                        ['name' => $tagName->value()],
                    ));
                }
                if ($row === null) {
                    throw new RuntimeException('Tag persistence failed.');
                }
                $tagId = MetadataId::fromStored((string) $row['tag_id']);
                $resolvedTagIds[$tagId->value()] = $tagId;
            }

            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_thread_tags` WHERE `thread_id` = :thread_id',
                ['thread_id' => $threadKey],
            ));
            foreach ($resolvedTagIds as $tagId) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_thread_tags` (`thread_id`, `tag_id`) VALUES (:thread_id, :tag_id)',
                    ['thread_id' => $threadKey, 'tag_id' => $tagId->value()],
                ));
            }

            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_thread_custom_field_values` WHERE `thread_id` = :thread_id',
                ['thread_id' => $threadKey],
            ));
            foreach ($metadata->fieldValues() as $fieldKey => $value) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_thread_custom_field_values` '
                    . '(`thread_id`, `field_key`, `value_json`) VALUES (:thread_id, :field_key, :value_json)',
                    [
                        'thread_id' => $threadKey,
                        'field_key' => $fieldKey,
                        'value_json' => $value->encoded(),
                    ],
                ));
            }
        });
    }

    public function forumFieldValues(EntityId $forumNodeId): array
    {
        ForumNodeId::assert($forumNodeId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT v.`field_key`, f.`value_type`, v.`value_json` '
            . 'FROM `forwext_forum_custom_field_values` v '
            . 'INNER JOIN `forwext_custom_fields` f ON f.`field_key` = v.`field_key` '
            . 'WHERE v.`forum_node_id` = :forum_node_id ORDER BY v.`field_key`',
            ['forum_node_id' => $forumNodeId->value()],
        ));
        return $this->hydrateValues($rows);
    }

    public function replaceForumFieldValues(EntityId $forumNodeId, array $values): void
    {
        ForumNodeId::assert($forumNodeId);
        foreach ($values as $fieldKey => $value) {
            CustomFieldKey::fromString((string) $fieldKey);
            if (!$value instanceof CustomFieldValue) {
                throw new InvalidArgumentException('Forum custom field values must be typed.');
            }
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($forumNodeId, $values): void {
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_forum_custom_field_values` WHERE `forum_node_id` = :forum_node_id',
                ['forum_node_id' => $forumNodeId->value()],
            ));
            foreach ($values as $fieldKey => $value) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_forum_custom_field_values` '
                    . '(`forum_node_id`, `field_key`, `value_json`) '
                    . 'VALUES (:forum_node_id, :field_key, :value_json)',
                    [
                        'forum_node_id' => $forumNodeId->value(),
                        'field_key' => $fieldKey,
                        'value_json' => $value->encoded(),
                    ],
                ));
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function hydratePrefix(array $row): ThreadPrefix
    {
        return new ThreadPrefix(
            MetadataId::fromStored((string) $row['prefix_id']),
            MetadataId::fromStored((string) $row['group_id']),
            (string) $row['name'],
            (int) $row['sort_order'],
            (bool) $row['enabled'],
        );
    }

    private function fieldSelectSql(): string
    {
        return 'SELECT `field_key`, `target`, `label`, `value_type`, `required`, `minimum_value`, '
            . '`maximum_value`, `choices_json`, `sort_order`, `enabled` FROM `forwext_custom_fields`';
    }

    /** @param array<string, mixed> $row */
    private function hydrateField(array $row): CustomFieldDefinition
    {
        try {
            $choices = json_decode((string) $row['choices_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored custom field choices JSON is invalid.', 0, $exception);
        }
        if (!is_array($choices)) {
            throw new RuntimeException('Stored custom field choices are invalid.');
        }
        foreach ($choices as $key => $label) {
            if (!is_string($key) || !is_string($label)) {
                throw new RuntimeException('Stored custom field choice is invalid.');
            }
        }

        return new CustomFieldDefinition(
            CustomFieldKey::fromString((string) $row['field_key']),
            CustomFieldTarget::from((string) $row['target']),
            (string) $row['label'],
            CustomFieldType::from((string) $row['value_type']),
            (bool) $row['required'],
            $row['minimum_value'] === null ? null : (int) $row['minimum_value'],
            $row['maximum_value'] === null ? null : (int) $row['maximum_value'],
            $choices,
            (int) $row['sort_order'],
            (bool) $row['enabled'],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, CustomFieldValue>
     */
    private function hydrateValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $key = CustomFieldKey::fromString((string) $row['field_key']);
            $values[$key->value()] = CustomFieldValue::fromStored(
                CustomFieldType::from((string) $row['value_type']),
                (string) $row['value_json'],
            );
        }
        return $values;
    }
}
