<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateDirectConversationSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261003150000_direct_conversation_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_direct_conversations ('
            . 'conversation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'pair_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'last_message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (conversation_id),'
            . 'UNIQUE KEY uq_forwext_direct_pair (pair_key),'
            . 'KEY idx_forwext_direct_updated (updated_at_utc,conversation_id),'
            . 'KEY idx_forwext_direct_last_message (last_message_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_direct_conversation_participants ('
            . 'conversation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'last_read_message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'last_read_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (conversation_id,user_id),'
            . 'KEY idx_forwext_direct_participant_user (user_id,conversation_id),'
            . 'CONSTRAINT fk_forwext_direct_participant_conversation FOREIGN KEY (conversation_id) '
            . 'REFERENCES forwext_direct_conversations (conversation_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_direct_participant_user FOREIGN KEY (user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_direct_messages ('
            . 'message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'conversation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'author_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'body TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (message_id),'
            . 'KEY idx_forwext_direct_message_conversation (conversation_id,created_at_utc,message_id),'
            . 'KEY idx_forwext_direct_message_author (author_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_direct_message_conversation FOREIGN KEY (conversation_id) '
            . 'REFERENCES forwext_direct_conversations (conversation_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_direct_message_author FOREIGN KEY (author_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('conversation.use','flag','Use member-to-member private conversations.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
            . 'updated_at_utc=VALUES(updated_at_utc)',
        ));
        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'conversation.use','allow',NULL) "
                . "ON DUPLICATE KEY UPDATE effect='allow',numeric_limit=NULL",
                ['template_key'=>$templateKey],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_direct_conversations','forwext_direct_conversation_participants',"
            . "'forwext_direct_messages')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_direct_participant_conversation','fk_forwext_direct_participant_user',"
            . "'fk_forwext_direct_message_conversation','fk_forwext_direct_message_author')",
        ));
        $pairIndex = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_direct_conversations' AND INDEX_NAME='uq_forwext_direct_pair' AND NON_UNIQUE=0",
        ));
        $permission = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='conversation.use' AND value_type='flag'",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='conversation.use' AND effect='allow'",
        ));

        return (int) $tables === 3
            && (int) $foreignKeys === 4
            && (int) $pairIndex === 1
            && (int) $permission === 1
            && (int) $rules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Direct-conversation schema, permission or indexes are incomplete.');
    }
}
