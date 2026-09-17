<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateProfileActivityTables implements Migration
{
    private const PERMISSIONS = [
        'profile.post.view' => 'View profile posts and profile activity when privacy permits.',
        'profile.post.create' => 'Create profile posts when the profile posting policy permits.',
        'profile.post.comment' => 'Comment on visible profile posts.',
        'profile.post.react' => 'React to visible profile posts.',
        'profile.post.moderate' => 'Moderate profile posts and comments across profiles.',
    ];

    public function id(): MigrationId { return MigrationId::fromString('20260916003000_profile_activity'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_profile_activity_settings` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`view_scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'everyone\', '
            . '`post_scope` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'everyone\', '
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`), '
            . 'CONSTRAINT `fk_forwext_profile_activity_settings_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_profile_posts` ('
            . '`profile_post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`profile_owner_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`author_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`body_source` VARCHAR(10000) NOT NULL, '
            . '`moderation_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'visible\', '
            . '`deleted_at_utc` DATETIME(6) NULL, `created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`profile_post_id`), '
            . 'KEY `idx_forwext_profile_posts_owner_created` (`profile_owner_user_id`, `created_at_utc`), '
            . 'KEY `idx_forwext_profile_posts_author_created` (`author_user_id`, `created_at_utc`), '
            . 'CONSTRAINT `fk_forwext_profile_posts_owner` FOREIGN KEY (`profile_owner_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_profile_posts_author` FOREIGN KEY (`author_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_profile_comments` ('
            . '`comment_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`profile_post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`author_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`body_source` VARCHAR(10000) NOT NULL, '
            . '`moderation_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'visible\', '
            . '`deleted_at_utc` DATETIME(6) NULL, `created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`comment_id`), KEY `idx_forwext_profile_comments_post_created` (`profile_post_id`, `created_at_utc`), '
            . 'CONSTRAINT `fk_forwext_profile_comments_post` FOREIGN KEY (`profile_post_id`) '
            . 'REFERENCES `forwext_profile_posts` (`profile_post_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_profile_comments_author` FOREIGN KEY (`author_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_profile_post_reactions` ('
            . '`profile_post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`reaction_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`profile_post_id`, `user_id`), KEY `idx_forwext_profile_post_reactions_type` (`reaction_key`, `profile_post_id`), '
            . 'CONSTRAINT `fk_forwext_profile_post_reactions_post` FOREIGN KEY (`profile_post_id`) '
            . 'REFERENCES `forwext_profile_posts` (`profile_post_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_profile_post_reactions_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_profile_post_reactions_type` FOREIGN KEY (`reaction_key`) '
            . 'REFERENCES `forwext_reaction_types` (`reaction_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` (`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) '
                . "VALUES (:permission_key, 'flag', :description, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), `description` = VALUES(`description`), '
                . '`updated_at_utc` = VALUES(`updated_at_utc`)',
                ['permission_key' => $key, 'description' => $description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $effect = $permissionKey === 'profile.post.moderate' && !in_array($templateKey, ['moderator','administrator'], true)
                    ? 'deny' : 'allow';
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` (`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . 'VALUES (:template_key, :permission_key, :effect, NULL) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL',
                    ['template_key' => $templateKey, 'permission_key' => $permissionKey, 'effect' => $effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_profile_activity_settings','forwext_profile_posts','forwext_profile_comments','forwext_profile_post_reactions')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_profile_activity_settings','forwext_profile_posts','forwext_profile_comments','forwext_profile_post_reactions')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('profile.post.view','profile.post.create','profile.post.comment','profile.post.react','profile.post.moderate')",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user','member','verified','moderator','administrator') "
            . "AND `permission_key` IN ('profile.post.view','profile.post.create','profile.post.comment','profile.post.react','profile.post.moderate')",
        ));
        return (int) $tables === 4 && (int) $foreignKeys === 8 && (int) $permissions === 5 && (int) $rules === 25
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Profile activity schema, privacy permissions or starter rules are incomplete.');
    }
}
