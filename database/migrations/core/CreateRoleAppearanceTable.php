<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateRoleAppearanceTable implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915230000_role_appearance');
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
            'CREATE TABLE IF NOT EXISTS `forwext_role_appearances` ('
            . '`role_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`text_color` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`gradient_from` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`gradient_to` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`gradient_angle` SMALLINT UNSIGNED NOT NULL DEFAULT 90, '
            . '`icon` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`banner_text` VARCHAR(64) NULL, '
            . '`banner_color` CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`pattern` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'none\', '
            . '`animation` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'none\', '
            . '`show_mobile` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`show_profile` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`show_posts` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . 'PRIMARY KEY (`role_id`), '
            . 'CONSTRAINT `fk_forwext_role_appearance_role` FOREIGN KEY (`role_id`) '
            . 'REFERENCES `forwext_roles` (`role_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_role_appearances\'',
        ));
        $foreignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . 'AND `TABLE_NAME` = \'forwext_role_appearances\' '
            . 'AND `CONSTRAINT_NAME` = \'fk_forwext_role_appearance_role\' '
            . 'AND `REFERENCED_TABLE_NAME` = \'forwext_roles\' '
            . 'AND `DELETE_RULE` = \'CASCADE\'',
        ));

        return (int) $table === 1 && (int) $foreignKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Role appearance table or cascading role foreign key is missing.');
    }
}
