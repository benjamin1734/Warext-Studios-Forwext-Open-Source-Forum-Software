<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class AddDiscoveryQueryIndexes implements Migration
{
    private const THREAD_INDEX = 'idx_forwext_threads_discovery';
    private const POST_INDEX = 'idx_forwext_posts_discovery_activity';

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260917230000_discovery_query_indexes');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        if (!$this->hasIndex($context, 'forwext_threads', self::THREAD_INDEX)) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_threads` ADD INDEX `' . self::THREAD_INDEX . '` '
                . '(`forum_node_id`, `deleted`, `moderation_state`, `merged_into_thread_id`, '
                . '`featured`, `created_at_utc`, `updated_at_utc`, `thread_id`)',
            ));
        }

        if (!$this->hasIndex($context, 'forwext_posts', self::POST_INDEX)) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_posts` ADD INDEX `' . self::POST_INDEX . '` '
                . '(`thread_id`, `deleted`, `moderation_state`, `updated_at_utc`, `position`)',
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        return $this->hasIndex($context, 'forwext_threads', self::THREAD_INDEX)
            && $this->hasIndex($context, 'forwext_posts', self::POST_INDEX)
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Discovery query indexes are incomplete.');
    }

    private function hasIndex(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table_name '
            . 'AND `INDEX_NAME` = :index_name',
            ['table_name' => $table, 'index_name' => $index],
        )) > 0;
    }
}
