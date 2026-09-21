<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class ScopePaymentRefundReference implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919214500_payment_refund_reference_scope');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $unique=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_payment_refunds' AND INDEX_NAME='uq_forwext_payment_refund_reference'"
        ));
        if($unique>0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_payment_refunds DROP INDEX uq_forwext_payment_refund_reference'
            ));
        }

        $index=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_payment_refunds' AND INDEX_NAME='idx_forwext_payment_refund_reference'"
        ));
        if($index===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_payment_refunds ADD KEY idx_forwext_payment_refund_reference(provider_refund_reference)'
            ));
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $unique=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_payment_refunds' AND INDEX_NAME='uq_forwext_payment_refund_reference'"
        ));
        $nonUnique=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_payment_refunds' AND INDEX_NAME='idx_forwext_payment_refund_reference' AND NON_UNIQUE=1"
        ));
        return $unique===0&&$nonUnique===1
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Payment refund reference index scope is invalid.');
    }
}
