<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePollTables implements Migration
{
    private const PERMISSIONS = [
        'forum.poll.create' => 'Create a poll on an authorized thread.',
        'forum.poll.vote' => 'Vote in an eligible poll.',
        'forum.poll.view_results' => 'View poll results when the poll result policy allows it.',
        'forum.poll.view_voters' => 'View voter identities for open-voter polls.',
        'forum.poll.manage' => 'Manage and manually close polls in an authorized forum.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235958_poll_system');
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
            'CREATE TABLE IF NOT EXISTS `forwext_polls` ('
            . '`poll_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`creator_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`question` VARCHAR(255) NOT NULL, '
            . '`selection_mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`max_selections` TINYINT UNSIGNED NOT NULL, '
            . '`change_vote` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`voter_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`result_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`closes_at_utc` DATETIME(6) NULL, `max_voters` INT UNSIGNED NULL, '
            . '`closed_at_utc` DATETIME(6) NULL, `created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`poll_id`), UNIQUE KEY `uq_forwext_polls_thread` (`thread_id`), '
            . 'KEY `idx_forwext_polls_creator` (`creator_user_id`), '
            . 'CONSTRAINT `fk_forwext_polls_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_polls_creator` FOREIGN KEY (`creator_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_poll_options` ('
            . '`option_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`poll_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`option_text` VARCHAR(200) NOT NULL, `sort_order` TINYINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (`option_id`), '
            . 'UNIQUE KEY `uq_forwext_poll_options_order` (`poll_id`, `sort_order`), '
            . 'KEY `idx_forwext_poll_options_poll` (`poll_id`, `option_id`), '
            . 'CONSTRAINT `fk_forwext_poll_options_poll` FOREIGN KEY (`poll_id`) '
            . 'REFERENCES `forwext_polls` (`poll_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_poll_votes` ('
            . '`vote_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`poll_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`vote_id`), UNIQUE KEY `uq_forwext_poll_votes_user` (`poll_id`, `user_id`), '
            . 'KEY `idx_forwext_poll_votes_user` (`user_id`), '
            . 'CONSTRAINT `fk_forwext_poll_votes_poll` FOREIGN KEY (`poll_id`) '
            . 'REFERENCES `forwext_polls` (`poll_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_poll_votes_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_poll_vote_choices` ('
            . '`vote_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`option_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (`vote_id`, `option_id`), KEY `idx_forwext_poll_choices_option` (`option_id`, `vote_id`), '
            . 'CONSTRAINT `fk_forwext_poll_choices_vote` FOREIGN KEY (`vote_id`) '
            . 'REFERENCES `forwext_poll_votes` (`vote_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_poll_choices_option` FOREIGN KEY (`option_id`) '
            . 'REFERENCES `forwext_poll_options` (`option_id`) ON DELETE CASCADE'
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

        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $staff = in_array($templateKey, ['moderator', 'administrator'], true);
                $member = in_array($templateKey, ['member', 'verified'], true);
                $effect = match ($permissionKey) {
                    'forum.poll.create' => ($member || $staff) ? 'allow' : 'deny',
                    'forum.poll.vote', 'forum.poll.view_results' => 'allow',
                    'forum.poll.view_voters' => ($member || $staff) ? 'allow' : 'deny',
                    'forum.poll.manage' => $staff ? 'allow' : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` '
                    . '(`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . 'VALUES (:template_key, :permission_key, :effect, NULL) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL',
                    [
                        'template_key' => $templateKey,
                        'permission_key' => $permissionKey,
                        'effect' => $effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_polls', 'forwext_poll_options', 'forwext_poll_votes', "
            . "'forwext_poll_vote_choices')",
        ));
        $threadUnique = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_polls\' '
            . 'AND `INDEX_NAME` = \'uq_forwext_polls_thread\' AND `NON_UNIQUE` = 0',
        ));
        $voteUnique = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_poll_votes\' '
            . 'AND `INDEX_NAME` = \'uq_forwext_poll_votes_user\' AND `NON_UNIQUE` = 0',
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.poll.create', 'forum.poll.vote', 'forum.poll.view_results', "
            . "'forum.poll.view_voters', 'forum.poll.manage')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.poll.create', 'forum.poll.vote', 'forum.poll.view_results', "
            . "'forum.poll.view_voters', 'forum.poll.manage')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_polls_thread', 'fk_forwext_polls_creator', "
            . "'fk_forwext_poll_options_poll', 'fk_forwext_poll_votes_poll', 'fk_forwext_poll_votes_user', "
            . "'fk_forwext_poll_choices_vote', 'fk_forwext_poll_choices_option')",
        ));

        return (int) $tables === 4
            && (int) $threadUnique === 1
            && (int) $voteUnique === 2
            && (int) $permissions === 5
            && (int) $templateRules === 25
            && (int) $foreignKeys === 7
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Poll schema, uniqueness constraints or permission seeds are incomplete.');
    }
}
