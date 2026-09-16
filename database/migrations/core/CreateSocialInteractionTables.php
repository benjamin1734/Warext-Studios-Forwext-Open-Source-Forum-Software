<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSocialInteractionTables implements Migration
{
    private const PERMISSIONS = [
        'forum.reaction.use' => 'React to visible forum posts.',
        'forum.bookmark.use' => 'Save private bookmarks and notes for visible forum posts.',
        'social.follow.use' => 'Follow eligible forum users.',
        'social.ignore.use' => 'Ignore forum users and filter their authored content.',
    ];

    /** @var array<string,array{label:string,score:int,order:int}> */
    private const REACTIONS = [
        'like' => ['label' => 'Like', 'score' => 1, 'order' => 10],
        'love' => ['label' => 'Love', 'score' => 1, 'order' => 20],
        'haha' => ['label' => 'Haha', 'score' => 1, 'order' => 30],
        'wow' => ['label' => 'Wow', 'score' => 1, 'order' => 40],
        'sad' => ['label' => 'Sad', 'score' => 0, 'order' => 50],
        'angry' => ['label' => 'Angry', 'score' => 0, 'order' => 60],
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260916002000_social_interactions');
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
            'CREATE TABLE IF NOT EXISTS `forwext_reaction_types` ('
            . '`reaction_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`label` VARCHAR(64) NOT NULL, `score` SMALLINT NOT NULL DEFAULT 0, '
            . '`enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, `display_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`reaction_key`), KEY `idx_forwext_reaction_types_order` (`enabled`, `display_order`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_post_reactions` ('
            . '`post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`reaction_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`post_id`, `user_id`), KEY `idx_forwext_post_reactions_type` (`reaction_key`, `post_id`), '
            . 'CONSTRAINT `fk_forwext_post_reactions_post` FOREIGN KEY (`post_id`) REFERENCES `forwext_posts` (`post_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_post_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_post_reactions_type` FOREIGN KEY (`reaction_key`) REFERENCES `forwext_reaction_types` (`reaction_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_post_bookmarks` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `note` VARCHAR(1000) NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `post_id`), KEY `idx_forwext_post_bookmarks_updated` (`user_id`, `updated_at_utc`), '
            . 'CONSTRAINT `fk_forwext_post_bookmarks_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_post_bookmarks_post` FOREIGN KEY (`post_id`) REFERENCES `forwext_posts` (`post_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_follows` ('
            . '`follower_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`followed_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`follower_user_id`, `followed_user_id`), '
            . 'KEY `idx_forwext_user_follows_target` (`followed_user_id`, `follower_user_id`), '
            . 'CONSTRAINT `fk_forwext_user_follows_source` FOREIGN KEY (`follower_user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_user_follows_target` FOREIGN KEY (`followed_user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_ignores` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`ignored_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`, `ignored_user_id`), '
            . 'KEY `idx_forwext_user_ignores_target` (`ignored_user_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_user_ignores_source` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_user_ignores_target` FOREIGN KEY (`ignored_user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::REACTIONS as $key => $reaction) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_reaction_types` (`reaction_key`, `label`, `score`, `enabled`, `display_order`) '
                . 'VALUES (:reaction_key, :label, :score, 1, :display_order) '
                . 'ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `score` = VALUES(`score`), '
                . '`display_order` = VALUES(`display_order`)',
                [
                    'reaction_key' => $key,
                    'label' => $reaction['label'],
                    'score' => $reaction['score'],
                    'display_order' => $reaction['order'],
                ],
            ));
        }

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

        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` '
                    . '(`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . "VALUES (:template_key, :permission_key, 'allow', NULL) "
                    . "ON DUPLICATE KEY UPDATE `effect` = 'allow', `numeric_limit` = NULL",
                    ['template_key' => $templateKey, 'permission_key' => $permissionKey],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_reaction_types','forwext_post_reactions','forwext_post_bookmarks',"
            . "'forwext_user_follows','forwext_user_ignores')",
        ));
        $reactions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_reaction_types` WHERE `reaction_key` IN '
            . "('like','love','haha','wow','sad','angry')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.reaction.use','forum.bookmark.use','social.follow.use','social.ignore.use')",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user','member','verified','moderator','administrator') "
            . "AND `permission_key` IN ('forum.reaction.use','forum.bookmark.use','social.follow.use','social.ignore.use')",
        ));

        return (int) $tables === 5 && (int) $reactions === 6 && (int) $permissions === 4 && (int) $rules === 20
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Social interaction schema, reactions, permissions or starter rules are incomplete.');
    }
}
