<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateUserPresenceTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260917233000_user_presence');
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
            'CREATE TABLE IF NOT EXISTS `forwext_user_presence` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`last_seen_at_utc` DATETIME(6) NOT NULL,'
            . "`visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'members',"
            . '`updated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`user_id`),'
            . 'KEY `idx_forwext_presence_online` (`visibility`, `last_seen_at_utc`),'
            . 'CONSTRAINT `fk_forwext_presence_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_user_presence'",
        ));
        $foreignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` = 'fk_forwext_presence_user'",
        ));

        return (int) $table === 1 && (int) $foreignKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('User presence table or user foreign key is missing.');
    }
}
