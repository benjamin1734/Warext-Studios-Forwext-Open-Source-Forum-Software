<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSearchAdvancedFilterTables implements Migration
{
    public function id(): MigrationId { return MigrationId::fromString('20260917004000_search_advanced_filters'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_search_document_attributes` ('
            . '`document_key` CHAR(64) NOT NULL,'
            . '`attribute_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`attribute_value` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY (`document_key`,`attribute_key`,`attribute_value`),'
            . 'KEY `idx_forwext_search_attribute_lookup` (`attribute_key`,`attribute_value`,`document_key`),'
            . 'CONSTRAINT `fk_forwext_search_attribute_document` FOREIGN KEY (`document_key`) '
            . 'REFERENCES `forwext_search_documents` (`document_key`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach ([
            ['user','forwext_users','user_id'], ['forum','forwext_nodes','node_id'],
            ['thread','forwext_threads','thread_id'], ['post','forwext_posts','post_id'],
        ] as [$type, $table, $id]) {
            $this->seedReindex($context, $type, $table, $id);
        }

        $this->createDependencyTriggers($context, 'prefix', 'forwext_thread_prefix_assignments');
        $this->createDependencyTriggers($context, 'tag', 'forwext_thread_tags');
        $this->createThreadTypeDependencyTrigger($context);
        $this->createNodeVisibilityDependencyTrigger($context);
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() '
            . "AND `TABLE_NAME`='forwext_search_document_attributes'",
        ));
        $index = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA`=DATABASE() '
            . "AND `TABLE_NAME`='forwext_search_document_attributes' AND `INDEX_NAME`='idx_forwext_search_attribute_lookup'",
        ));
        $foreignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA`=DATABASE() '
            . "AND `CONSTRAINT_NAME`='fk_forwext_search_attribute_document' AND `DELETE_RULE`='CASCADE'",
        ));
        $triggers = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TRIGGERS` WHERE `TRIGGER_SCHEMA`=DATABASE() '
            . "AND `TRIGGER_NAME` LIKE 'trg_forwext_filtermeta_%'",
        ));

        return (int) $table === 1 && (int) $index >= 1 && (int) $foreignKey === 1 && (int) $triggers === 8
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Advanced search attribute table, lookup index, cascade or metadata triggers are incomplete.');
    }

    private function seedReindex(MigrationContext $context, string $type, string $table, string $id): void
    {
        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . 'SELECT :document_type,`' . $id . '`,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6) FROM `' . $table . '` '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`attempts`=0,`available_at_utc`=UTC_TIMESTAMP(6),'
            . '`locked_until_utc`=NULL,`last_error_code`=NULL,`updated_at_utc`=UTC_TIMESTAMP(6)',
            ['document_type' => $type],
        ));
    }

    private function createDependencyTriggers(MigrationContext $context, string $name, string $table): void
    {
        foreach (['insert','update','delete'] as $event) {
            $trigger = 'trg_forwext_filtermeta_' . $name . '_' . $event;
            $context->execute(new CompiledQuery('DROP TRIGGER IF EXISTS `' . $trigger . '`'));
        }

        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_filtermeta_' . $name . '_insert` AFTER INSERT ON `' . $table . '` FOR EACH ROW '
            . 'BEGIN ' . self::queueThreadSql('NEW.`thread_id`') . '; ' . self::queuePostsSql('NEW.`thread_id`') . '; END',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_filtermeta_' . $name . '_delete` AFTER DELETE ON `' . $table . '` FOR EACH ROW '
            . 'BEGIN ' . self::queueThreadSql('OLD.`thread_id`') . '; ' . self::queuePostsSql('OLD.`thread_id`') . '; END',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_filtermeta_' . $name . '_update` AFTER UPDATE ON `' . $table . '` FOR EACH ROW '
            . 'BEGIN '
            . self::queueThreadSql('NEW.`thread_id`') . '; ' . self::queuePostsSql('NEW.`thread_id`') . '; '
            . 'IF NOT (OLD.`thread_id` <=> NEW.`thread_id`) THEN '
            . self::queueThreadSql('OLD.`thread_id`') . '; ' . self::queuePostsSql('OLD.`thread_id`') . '; END IF; '
            . 'END',
        ));
    }

    private function createThreadTypeDependencyTrigger(MigrationContext $context): void
    {
        $name = 'trg_forwext_filtermeta_thread_type_update';
        $context->execute(new CompiledQuery('DROP TRIGGER IF EXISTS `' . $name . '`'));
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `' . $name . '` AFTER UPDATE ON `forwext_threads` FOR EACH ROW '
            . 'BEGIN IF NOT (OLD.`type_key` <=> NEW.`type_key`) THEN '
            . self::queuePostsSql('NEW.`thread_id`') . '; END IF; END',
        ));
    }

    private function createNodeVisibilityDependencyTrigger(MigrationContext $context): void
    {
        $name = 'trg_forwext_filtermeta_node_visibility_update';
        $context->execute(new CompiledQuery('DROP TRIGGER IF EXISTS `' . $name . '`'));
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `' . $name . '` AFTER UPDATE ON `forwext_nodes` FOR EACH ROW '
            . 'BEGIN IF NOT (OLD.`visibility` <=> NEW.`visibility`) THEN '
            . self::queueNodeThreadsSql('NEW.`node_id`') . '; '
            . self::queueNodePostsSql('NEW.`node_id`') . '; END IF; END',
        ));
    }

    private static function queueThreadSql(string $threadExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "VALUES ('thread'," . $threadExpression . ',1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`attempts`=0,`available_at_utc`=UTC_TIMESTAMP(6),'
            . '`locked_until_utc`=NULL,`last_error_code`=NULL,`updated_at_utc`=UTC_TIMESTAMP(6)';
    }


    private static function queueNodeThreadsSql(string $nodeExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "SELECT 'thread',t.`thread_id`,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6) FROM `forwext_threads` t "
            . 'WHERE t.`forum_node_id`=' . $nodeExpression . ' '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`attempts`=0,`available_at_utc`=UTC_TIMESTAMP(6),'
            . '`locked_until_utc`=NULL,`last_error_code`=NULL,`updated_at_utc`=UTC_TIMESTAMP(6)';
    }

    private static function queueNodePostsSql(string $nodeExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "SELECT 'post',p.`post_id`,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6) "
            . 'FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id`=p.`thread_id` '
            . 'WHERE t.`forum_node_id`=' . $nodeExpression . ' '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`attempts`=0,`available_at_utc`=UTC_TIMESTAMP(6),'
            . '`locked_until_utc`=NULL,`last_error_code`=NULL,`updated_at_utc`=UTC_TIMESTAMP(6)';
    }

    private static function queuePostsSql(string $threadExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "SELECT 'post',p.`post_id`,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6) FROM `forwext_posts` p "
            . 'WHERE p.`thread_id`=' . $threadExpression . ' '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`attempts`=0,`available_at_utc`=UTC_TIMESTAMP(6),'
            . '`locked_until_utc`=NULL,`last_error_code`=NULL,`updated_at_utc`=UTC_TIMESTAMP(6)';
    }
}
