<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateCommunityGroups implements Migration
{
    private const PERMISSIONS = [
        'group.view'=>'View active community groups and their public memberships.',
        'group.create'=>'Create community groups.',
        'group.join'=>'Join or request membership in community groups.',
        'group.manage_own'=>'Manage a community group as its owner or assigned moderator.',
        'group.moderate_any'=>'Moderate any community group and its memberships.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20261005213000_community_groups');
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
            'CREATE TABLE IF NOT EXISTS forwext_groups ('
            . 'group_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,'
            . "tagline VARCHAR(240) NOT NULL DEFAULT '',"
            . 'description MEDIUMTEXT NOT NULL,'
            . "join_policy VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'approval',"
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',"
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(group_id),'
            . 'UNIQUE KEY uq_forwext_group_slug(slug),'
            . 'KEY idx_forwext_group_directory(state,updated_at_utc,group_id),'
            . 'KEY idx_forwext_group_owner(owner_user_id,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_group_owner FOREIGN KEY(owner_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_group_members ('
            . 'group_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "role_key VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'member',"
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'acted_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(group_id,user_id),'
            . 'KEY idx_forwext_group_member_user(user_id,state,role_key,group_id),'
            . 'KEY idx_forwext_group_member_queue(group_id,state,created_at_utc,user_id),'
            . 'CONSTRAINT fk_forwext_group_member_group FOREIGN KEY(group_id) '
            . 'REFERENCES forwext_groups(group_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_group_member_user FOREIGN KEY(user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_group_member_actor FOREIGN KEY(acted_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['key'=>$key,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $template) {
            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $effect = match ($permission) {
                    'group.view' => 'allow',
                    'group.create','group.join','group.manage_own'
                        => $template === 'new_user' ? 'deny' : 'allow',
                    'group.moderate_any'
                        => in_array($template, ['moderator','administrator'], true) ? 'allow' : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_groups','forwext_group_members')",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_group_owner','fk_forwext_group_member_group','fk_forwext_group_member_user',"
            . "'fk_forwext_group_member_actor')",
        ));
        $permissions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key LIKE 'group.%' AND value_type='flag'",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key LIKE 'group.%'",
        ));
        $slugIndex = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_groups' AND INDEX_NAME='uq_forwext_group_slug' AND NON_UNIQUE=0",
        ));

        return $tables === 2 && $foreignKeys === 4 && $permissions === 5 && $rules === 25 && $slugIndex === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Community group schema or permission defaults are incomplete.');
    }
}
