<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAttachmentPipelineTables implements Migration
{
    private const PERMISSIONS = [
        'forum.attachment.upload' => 'Upload and finalize attachments in an authorized forum.',
        'forum.attachment.download' => 'Download attachments from authorized forum content.',
        'forum.attachment.manage_any' => 'Manage attachments owned by other users in an authorized forum.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260916001000_attachment_pipeline');
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
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_attachments` ('
            . '`attachment_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`owner_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`filename` VARCHAR(180) NOT NULL, '
            . '`media_type` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`extension` VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`size_bytes` BIGINT UNSIGNED NOT NULL, '
            . '`sha256_hex` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`storage_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`thumbnail_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`image_width` INT UNSIGNED NULL, `image_height` INT UNSIGNED NULL, '
            . '`metadata_stripped` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, `expires_at_utc` DATETIME(6) NOT NULL, '
            . '`attached_at_utc` DATETIME(6) NULL, '
            . 'PRIMARY KEY (`attachment_id`), '
            . 'KEY `idx_forwext_attachments_owner_state` (`owner_user_id`, `state`), '
            . 'KEY `idx_forwext_attachments_post` (`post_id`), '
            . 'KEY `idx_forwext_attachments_expiry` (`state`, `expires_at_utc`), '
            . 'CONSTRAINT `fk_forwext_attachments_owner` FOREIGN KEY (`owner_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE RESTRICT, '
            . 'CONSTRAINT `fk_forwext_attachments_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE RESTRICT, '
            . 'CONSTRAINT `fk_forwext_attachments_post` FOREIGN KEY (`post_id`) '
            . 'REFERENCES `forwext_posts` (`post_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` '
                . '(`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) '
                . "VALUES (:permission_key, 'flag', :description, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), '
                . '`description` = VALUES(`description`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                ['permission_key' => $key, 'description' => $description],
            ));
        }

        $profiles = [
            'new_user' => ['upload' => 'deny', 'download' => 'allow', 'manage' => 'deny'],
            'member' => ['upload' => 'allow', 'download' => 'allow', 'manage' => 'deny'],
            'verified' => ['upload' => 'allow', 'download' => 'allow', 'manage' => 'deny'],
            'moderator' => ['upload' => 'allow', 'download' => 'allow', 'manage' => 'allow'],
            'administrator' => ['upload' => 'allow', 'download' => 'allow', 'manage' => 'allow'],
        ];
        $permissionByKind = [
            'upload' => 'forum.attachment.upload',
            'download' => 'forum.attachment.download',
            'manage' => 'forum.attachment.manage_any',
        ];
        foreach ($profiles as $templateKey => $effects) {
            foreach ($permissionByKind as $kind => $permissionKey) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` '
                    . '(`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . 'VALUES (:template_key, :permission_key, :effect, NULL) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL',
                    [
                        'template_key' => $templateKey,
                        'permission_key' => $permissionKey,
                        'effect' => $effects[$kind],
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_attachments\'',
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_attachments\'',
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT `INDEX_NAME`) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_attachments\' '
            . "AND `INDEX_NAME` IN ('idx_forwext_attachments_owner_state', 'idx_forwext_attachments_post', "
            . "'idx_forwext_attachments_expiry')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.attachment.upload', 'forum.attachment.download', 'forum.attachment.manage_any')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.attachment.upload', 'forum.attachment.download', "
            . "'forum.attachment.manage_any')",
        ));

        return (int) $table === 1
            && (int) $foreignKeys === 3
            && (int) $indexes === 3
            && (int) $permissions === 3
            && (int) $templateRules === 15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Attachment schema, indexes, permissions or starter rules are incomplete.');
    }
}
