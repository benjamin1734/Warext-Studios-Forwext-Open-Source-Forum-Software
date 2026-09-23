<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateOperationsAnalyticsIndexes implements Migration
{
    /** @var array<string,array<string,list<string>>> */
    private const INDEXES = [
        'forwext_reports' => [
            'idx_forwext_reports_created' => ['created_at_utc', 'group_id'],
        ],
        'forwext_report_groups' => [
            'idx_forwext_report_groups_created' => ['created_at_utc', 'status', 'assigned_moderator_user_id'],
            'idx_forwext_report_groups_updated_status' => ['updated_at_utc', 'status', 'assigned_moderator_user_id'],
        ],
        'forwext_discipline_actions' => [
            'idx_forwext_discipline_started_type_actor' => ['starts_at_utc', 'action_type', 'actor_user_id'],
        ],
        'forwext_support_tickets' => [
            'idx_forwext_support_created' => ['created_at_utc', 'status', 'assigned_user_id'],
            'idx_forwext_support_resolved' => ['resolved_at_utc', 'status'],
            'idx_forwext_support_closed' => ['closed_at_utc', 'status'],
        ],
        'forwext_bug_reports' => [
            'idx_forwext_bug_created' => ['created_at_utc', 'status', 'category_key', 'assigned_user_id'],
            'idx_forwext_bug_finalized' => ['finalized_at_utc', 'status', 'category_key'],
        ],
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260923190000_operations_analytics_indexes');
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
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $columns) {
                if ($this->hasIndex($context, $table, $index)) {
                    continue;
                }
                $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
                $context->execute(new CompiledQuery(
                    'ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (' . implode(',', $quoted) . ')',
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $index) {
                if (!$this->hasIndex($context, $table, $index)) {
                    return MigrationVerification::failed('Operations analytics query index is missing: ' . $index);
                }
            }
        }

        return MigrationVerification::passed();
    }

    private function hasIndex(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index',
            ['table' => $table, 'index' => $index],
        )) > 0;
    }
}
