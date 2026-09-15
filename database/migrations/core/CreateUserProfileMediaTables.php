<?php

declare(strict_types=1);
namespace Forwext\Database\Migrations\Core;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;
final readonly class CreateUserProfileMediaTables implements Migration
{
    public function id(): MigrationId{return MigrationId::fromString('20260915130000_user_profile_media');}
    public function owner(): MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;} public function isTransactional():bool{return false;}
    public function up(MigrationContext $c):void
    {
        $c->execute(new CompiledQuery('CREATE TABLE IF NOT EXISTS `forwext_user_profiles` (`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`about` TEXT NOT NULL,`avatar_path` VARCHAR(1024) NULL,`banner_path` VARCHAR(1024) NULL,`profile_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`about_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`social_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`media_visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`updated_at_utc` DATETIME(6) NOT NULL,PRIMARY KEY(`user_id`),CONSTRAINT `fk_profile_user` FOREIGN KEY(`user_id`) REFERENCES `forwext_users`(`user_id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
        $c->execute(new CompiledQuery('CREATE TABLE IF NOT EXISTS `forwext_user_social_links` (`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`link_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`url` VARCHAR(2048) NOT NULL,`visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(`user_id`,`link_key`),CONSTRAINT `fk_social_user` FOREIGN KEY(`user_id`) REFERENCES `forwext_users`(`user_id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
        $c->execute(new CompiledQuery('CREATE TABLE IF NOT EXISTS `forwext_user_profile_tabs` (`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`tab_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,`enabled` TINYINT(1) NOT NULL DEFAULT 1,`visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\',`sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(`user_id`,`tab_key`),CONSTRAINT `fk_profile_tab_user` FOREIGN KEY(`user_id`) REFERENCES `forwext_users`(`user_id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    }
    public function verify(MigrationContext $c):MigrationVerification{$n=$c->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME` IN (\'forwext_user_profiles\',\'forwext_user_social_links\',\'forwext_user_profile_tabs\')'));return(int)$n===3?MigrationVerification::passed():MigrationVerification::failed('Profile/media tables are missing.');}
}
