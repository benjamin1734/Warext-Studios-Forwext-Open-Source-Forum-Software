<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSpellcheckSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919080000_spellcheck_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_spellcheck_site_dictionary ('
            . 'language VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'normalized_word VARCHAR(96) NOT NULL,'
            . 'word VARCHAR(96) NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (language,normalized_word),'
            . 'KEY idx_forwext_spellcheck_site_actor (actor_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_spellcheck_site_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_spellcheck_user_dictionary ('
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'language VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'normalized_word VARCHAR(96) NOT NULL,'
            . 'word VARCHAR(96) NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (user_id,language,normalized_word),'
            . 'KEY idx_forwext_spellcheck_user_language (language,normalized_word,user_id),'
            . 'CONSTRAINT fk_forwext_spellcheck_user_owner FOREIGN KEY (user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            ['spellcheck.use', 'Use writing and spelling assistance.'],
            ['spellcheck.dictionary.manage_own', 'Manage own spellcheck dictionary entries.'],
            ['spellcheck.dictionary.manage_site', 'Manage the site spellcheck dictionary.'],
        ];
        foreach ($permissions as [$key, $description]) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:permission_key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$key,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (['spellcheck.use','spellcheck.dictionary.manage_own','spellcheck.dictionary.manage_site'] as $permissionKey) {
                $effect = $permissionKey === 'spellcheck.dictionary.manage_site'
                    ? ($templateKey === 'administrator' ? 'allow' : 'deny')
                    : 'allow';
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    [
                        'template_key'=>$templateKey,
                        'permission_key'=>$permissionKey,
                        'effect'=>$effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_spellcheck_site_dictionary','forwext_spellcheck_user_dictionary')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() '
            . "AND CONSTRAINT_NAME IN ('fk_forwext_spellcheck_site_actor','fk_forwext_spellcheck_user_owner')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permissions '
            . "WHERE permission_key IN ('spellcheck.use','spellcheck.dictionary.manage_own','spellcheck.dictionary.manage_site') "
            . "AND value_type='flag'",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('spellcheck.use','spellcheck.dictionary.manage_own','spellcheck.dictionary.manage_site')",
        ));

        return (int) $tables === 2
            && (int) $foreignKeys === 2
            && (int) $permissions === 3
            && (int) $rules === 15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Spellcheck dictionaries or permission defaults are incomplete.');
    }
}
