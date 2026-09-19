<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateGiveawayDomain implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919143000_giveaway_domain');
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
            'CREATE TABLE IF NOT EXISTS forwext_giveaways ('
            . 'giveaway_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(180) NOT NULL,description MEDIUMTEXT NOT NULL,'
            . 'prize_title VARCHAR(180) NOT NULL,prize_description VARCHAR(2000) NOT NULL DEFAULT \'\','
            . 'prize_quantity INT UNSIGNED NOT NULL DEFAULT 1,participation_terms TEXT NOT NULL,'
            . 'starts_at_utc DATETIME(6) NOT NULL,ends_at_utc DATETIME(6) NOT NULL,'
            . 'entries_per_user SMALLINT UNSIGNED NOT NULL DEFAULT 1,max_participants INT UNSIGNED NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',"
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(giveaway_id),UNIQUE KEY uq_forwext_giveaway_slug(slug),'
            . 'KEY idx_forwext_giveaway_public(state,starts_at_utc,ends_at_utc,giveaway_id),'
            . 'KEY idx_forwext_giveaway_owner(owner_user_id,state,updated_at_utc),'
            . 'KEY idx_forwext_giveaway_due(state,starts_at_utc,ends_at_utc),'
            . 'CONSTRAINT fk_forwext_giveaway_owner FOREIGN KEY(owner_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $profiles = [
            'new_user'=>['view'=>'allow','enter'=>'deny','create'=>'deny','manage'=>'deny'],
            'member'=>['view'=>'allow','enter'=>'allow','create'=>'deny','manage'=>'deny'],
            'verified'=>['view'=>'allow','enter'=>'allow','create'=>'deny','manage'=>'deny'],
            'moderator'=>['view'=>'allow','enter'=>'allow','create'=>'allow','manage'=>'allow'],
            'administrator'=>['view'=>'allow','enter'=>'allow','create'=>'allow','manage'=>'allow'],
        ];
        $keys = [
            'view'=>'giveaway.view',
            'enter'=>'giveaway.enter',
            'create'=>'giveaway.create',
            'manage'=>'giveaway.manage',
        ];
        foreach ($profiles as $template=>$effects) {
            foreach ($keys as $kind=>$permission) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effects[$kind]],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_giveaways'",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaways' "
            . "AND INDEX_NAME IN ('uq_forwext_giveaway_slug','idx_forwext_giveaway_public',"
            . "'idx_forwext_giveaway_owner','idx_forwext_giveaway_due')",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('giveaway.view','giveaway.enter','giveaway.create','giveaway.manage')",
        ));

        return $table === 1 && $indexes === 4 && $rules === 20
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Giveaway domain schema or permission defaults are incomplete.');
    }
}
