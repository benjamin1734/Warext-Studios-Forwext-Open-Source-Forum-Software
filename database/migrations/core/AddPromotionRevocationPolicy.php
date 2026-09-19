<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class AddPromotionRevocationPolicy implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919181500_promotion_revocation_policy');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $exists=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_promotions' AND COLUMN_NAME='revoke_when_unqualified'"
        ));
        if($exists===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_promotions ADD COLUMN revoke_when_unqualified TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER units'
            ));
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $exists=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_promotions' AND COLUMN_NAME='revoke_when_unqualified'"
        ));
        return $exists===1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Promotion revocation policy column is missing.');
    }
}
