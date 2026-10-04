<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class AddDirectConversationParticipantManagement implements Migration
{
    private const TABLE = 'forwext_direct_conversation_participants';
    private const STATE_INDEX = 'idx_forwext_direct_participant_state';

    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004175500_direct_conversation_participant_management');
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
        if (!$this->hasColumn($context, 'starred_at_utc')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `' . self::TABLE . '` ADD COLUMN `starred_at_utc` DATETIME(6) NULL AFTER `last_read_at_utc`',
            ));
        }

        if (!$this->hasColumn($context, 'left_at_utc')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `' . self::TABLE . '` ADD COLUMN `left_at_utc` DATETIME(6) NULL AFTER `starred_at_utc`',
            ));
        }

        if (!$this->hasIndex($context, self::STATE_INDEX)) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `' . self::TABLE . '` ADD INDEX `' . self::STATE_INDEX
                . '` (`user_id`,`left_at_utc`,`starred_at_utc`,`conversation_id`)',
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $columns = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND TABLE_NAME=:table_name AND COLUMN_NAME IN (\'starred_at_utc\',\'left_at_utc\')',
            ['table_name'=>self::TABLE],
        ));
        $index = $this->hasIndex($context, self::STATE_INDEX);

        return $columns === 2 && $index
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Direct-conversation participant management state is incomplete.');
    }

    private function hasColumn(MigrationContext $context, string $column): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name',
            ['table_name'=>self::TABLE,'column_name'=>$column],
        )) > 0;
    }

    private function hasIndex(MigrationContext $context, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name',
            ['table_name'=>self::TABLE,'index_name'=>$index],
        )) > 0;
    }
}
