<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateForumAnalyticsDashboard implements Migration
{
    /** @var array<string,array{table:string,columns:string}> */
    private const INDEXES = [
        'idx_forwext_users_created' => ['table'=>'forwext_users','columns'=>'`created_at_utc`,`user_id`'],
        'idx_forwext_threads_created' => ['table'=>'forwext_threads','columns'=>'`created_at_utc`,`deleted`,`moderation_state`,`merged_into_thread_id`,`thread_id`'],
        'idx_forwext_posts_created' => ['table'=>'forwext_posts','columns'=>'`created_at_utc`,`deleted`,`moderation_state`,`post_id`'],
        'idx_forwext_presence_last_seen' => ['table'=>'forwext_user_presence','columns'=>'`last_seen_at_utc`,`user_id`'],
        'idx_forwext_analytics_active_time' => ['table'=>'forwext_analytics_events','columns'=>'`event_key`,`occurred_at_utc`,`actor_hash`'],
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260921231000_forum_analytics_dashboard');
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
        foreach (self::INDEXES as $index => $definition) {
            if (!$this->hasIndex($context, $definition['table'], $index)) {
                $context->execute(new CompiledQuery(
                    'ALTER TABLE `' . $definition['table'] . '` ADD INDEX `' . $index . '` (' . $definition['columns'] . ')',
                ));
            }
        }

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_permissions` '
            . '(`permission_key`,`value_type`,`description`,`created_at_utc`,`updated_at_utc`) '
            . "VALUES ('analytics.view_site','flag','View site-wide analytics and business intelligence.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`), '
            . '`description`=VALUES(`description`), `updated_at_utc`=VALUES(`updated_at_utc`)',
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $template) {
            $effect = $template === 'administrator' ? 'allow' : 'deny';
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permission_template_rules` '
                . '(`template_key`,`permission_key`,`effect`,`numeric_limit`) '
                . "VALUES (:template,'analytics.view_site',:effect,NULL) "
                . 'ON DUPLICATE KEY UPDATE `effect`=VALUES(`effect`), `numeric_limit`=NULL',
                ['template'=>$template,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        foreach (self::INDEXES as $index => $definition) {
            if (!$this->hasIndex($context, $definition['table'], $index)) {
                return MigrationVerification::failed('Forum analytics query index is missing: ' . $index);
            }
        }

        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_permission_template_rules` "
            . "WHERE `permission_key`='analytics.view_site' "
            . "AND `template_key` IN ('new_user','member','verified','moderator','administrator')",
        ));
        $admin = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_permission_template_rules` "
            . "WHERE `permission_key`='analytics.view_site' AND `template_key`='administrator' AND `effect`='allow'",
        ));
        $nonAdminAllows = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_permission_template_rules` "
            . "WHERE `permission_key`='analytics.view_site' "
            . "AND `template_key` IN ('new_user','member','verified','moderator') AND `effect`='allow'",
        ));

        return $rules === 5 && $admin === 1 && $nonAdminAllows === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Forum analytics permission defaults are incomplete.');
    }

    private function hasIndex(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=:table AND `INDEX_NAME`=:index',
            ['table'=>$table,'index'=>$index],
        )) > 0;
    }
}
