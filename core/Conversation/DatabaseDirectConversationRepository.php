<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseDirectConversationRepository implements DirectConversationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function findPair(EntityId $leftUserId, EntityId $rightUserId): ?EntityId
    {
        UserId::assert($leftUserId);
        UserId::assert($rightUserId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT conversation_id FROM forwext_direct_conversations WHERE pair_key=:pair_key LIMIT 1',
            ['pair_key'=>self::pairKey($leftUserId, $rightUserId)],
        ));
        return $row === null ? null : EntityId::fromString((string) $row['conversation_id']);
    }

    public function create(
        EntityId $actorId,
        EntityId $targetUserId,
        string $body,
        DateTimeImmutable $at,
    ): EntityId {
        UserId::assert($actorId);
        UserId::assert($targetUserId);
        if ($actorId->equals($targetUserId)) {
            throw new InvalidArgumentException('Direct conversation participants must be different users.');
        }
        $at = self::utc($at);
        $pairKey = self::pairKey($actorId, $targetUserId);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $actorId,
            $targetUserId,
            $body,
            $at,
            $pairKey,
        ): EntityId {
            $candidate = EntityId::generate();
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_direct_conversations '
                . '(conversation_id,pair_key,last_message_id,created_at_utc,updated_at_utc) '
                . 'VALUES (:conversation_id,:pair_key,NULL,:created_at,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE updated_at_utc=updated_at_utc',
                [
                    'conversation_id'=>$candidate->value(),
                    'pair_key'=>$pairKey,
                    'created_at'=>self::format($at),
                    'updated_at'=>self::format($at),
                ],
            ));
            $row = $database->fetchOne(new CompiledQuery(
                'SELECT conversation_id FROM forwext_direct_conversations WHERE pair_key=:pair_key LIMIT 1 FOR UPDATE',
                ['pair_key'=>$pairKey],
                true,
            ));
            if ($row === null) {
                throw new RuntimeException('Direct conversation could not be resolved after creation.');
            }
            $conversationId = EntityId::fromString((string) $row['conversation_id']);

            foreach ([$actorId, $targetUserId] as $participantId) {
                $database->execute(new CompiledQuery(
                    'INSERT IGNORE INTO forwext_direct_conversation_participants '
                    . '(conversation_id,user_id,last_read_message_id,last_read_at_utc,created_at_utc) '
                    . 'VALUES (:conversation_id,:user_id,NULL,NULL,:created_at)',
                    [
                        'conversation_id'=>$conversationId->value(),
                        'user_id'=>$participantId->value(),
                        'created_at'=>self::format($at),
                    ],
                ));
            }

            $message = $this->insertMessage($database, $actorId, $conversationId, $body, $at);
            $this->touchConversation($database, $conversationId, $message->messageId, $at);
            return $conversationId;
        });
    }

    public function append(
        EntityId $actorId,
        EntityId $conversationId,
        string $body,
        DateTimeImmutable $at,
    ): DirectMessage {
        UserId::assert($actorId);
        $at = self::utc($at);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $actorId,
            $conversationId,
            $body,
            $at,
        ): DirectMessage {
            $participant = $database->fetchOne(new CompiledQuery(
                'SELECT user_id FROM forwext_direct_conversation_participants '
                . 'WHERE conversation_id=:conversation_id AND user_id=:user_id AND left_at_utc IS NULL LIMIT 1 FOR UPDATE',
                ['conversation_id'=>$conversationId->value(),'user_id'=>$actorId->value()],
                true,
            ));
            if ($participant === null) {
                throw new InvalidArgumentException('Direct conversation is unavailable for this user.');
            }

            $message = $this->insertMessage($database, $actorId, $conversationId, $body, $at);
            $this->touchConversation($database, $conversationId, $message->messageId, $at);
            return $message;
        });
    }

    public function summaries(EntityId $actorId, int $limit = 30, int $offset = 0, bool $starredOnly = false): array
    {
        UserId::assert($actorId);
        self::assertPage($limit, $offset);
        $starredFilter = $starredOnly ? ' AND p.starred_at_utc IS NOT NULL ' : ' ';

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT c.conversation_id,op.user_id AS other_user_id,COALESCE(u.username,\'Silinmiş kullanıcı\') AS other_username,'
            . 'COALESCE(lm.body,\'\') AS last_message_body,c.updated_at_utc,(p.starred_at_utc IS NOT NULL) AS starred,'
            . '(SELECT COUNT(*) FROM forwext_direct_messages um '
            . 'WHERE um.conversation_id=c.conversation_id AND (um.author_user_id IS NULL OR um.author_user_id<>:unread_actor_id) '
            . 'AND (p.last_read_at_utc IS NULL OR um.created_at_utc>p.last_read_at_utc)) AS unread_count '
            . 'FROM forwext_direct_conversation_participants p '
            . 'INNER JOIN forwext_direct_conversations c ON c.conversation_id=p.conversation_id '
            . 'INNER JOIN forwext_direct_conversation_participants op '
            . 'ON op.conversation_id=c.conversation_id AND op.user_id<>p.user_id '
            . 'LEFT JOIN forwext_users u ON u.user_id=op.user_id '
            . 'LEFT JOIN forwext_direct_messages lm ON lm.message_id=c.last_message_id '
            . 'WHERE p.user_id=:actor_id AND p.left_at_utc IS NULL' . $starredFilter
            . 'ORDER BY c.updated_at_utc DESC,c.conversation_id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['actor_id'=>$actorId->value(),'unread_actor_id'=>$actorId->value()],
        ));

        return array_map($this->hydrateSummary(...), $rows);
    }

    public function view(EntityId $actorId, EntityId $conversationId, int $messageLimit = 200): ?DirectConversationView
    {
        UserId::assert($actorId);
        if ($messageLimit < 1 || $messageLimit > 500) {
            throw new InvalidArgumentException('Direct message view limit is invalid.');
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT c.conversation_id,op.user_id AS other_user_id,COALESCE(u.username,\'Silinmiş kullanıcı\') AS other_username,'
            . 'COALESCE(lm.body,\'\') AS last_message_body,c.updated_at_utc,(p.starred_at_utc IS NOT NULL) AS starred,'
            . '(SELECT COUNT(*) FROM forwext_direct_messages um '
            . 'WHERE um.conversation_id=c.conversation_id AND (um.author_user_id IS NULL OR um.author_user_id<>:unread_actor_id) '
            . 'AND (p.last_read_at_utc IS NULL OR um.created_at_utc>p.last_read_at_utc)) AS unread_count '
            . 'FROM forwext_direct_conversation_participants p '
            . 'INNER JOIN forwext_direct_conversations c ON c.conversation_id=p.conversation_id '
            . 'INNER JOIN forwext_direct_conversation_participants op '
            . 'ON op.conversation_id=c.conversation_id AND op.user_id<>p.user_id '
            . 'LEFT JOIN forwext_users u ON u.user_id=op.user_id '
            . 'LEFT JOIN forwext_direct_messages lm ON lm.message_id=c.last_message_id '
            . 'WHERE p.user_id=:actor_id AND p.left_at_utc IS NULL AND c.conversation_id=:conversation_id LIMIT 1',
            [
                'actor_id'=>$actorId->value(),
                'unread_actor_id'=>$actorId->value(),
                'conversation_id'=>$conversationId->value(),
            ],
        ));
        if ($row === null) {
            return null;
        }

        $messageRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT m.message_id,m.conversation_id,m.author_user_id,COALESCE(u.username,\'Silinmiş kullanıcı\') AS author_username,'
            . 'm.body,m.created_at_utc FROM forwext_direct_messages m '
            . 'LEFT JOIN forwext_users u ON u.user_id=m.author_user_id '
            . 'WHERE m.conversation_id=:conversation_id '
            . 'ORDER BY m.created_at_utc DESC,m.message_id DESC LIMIT ' . $messageLimit,
            ['conversation_id'=>$conversationId->value()],
        ));
        $messages = array_reverse(array_map($this->hydrateMessage(...), $messageRows));

        return new DirectConversationView($this->hydrateSummary($row), $messages);
    }

    public function otherParticipant(EntityId $actorId, EntityId $conversationId): ?EntityId
    {
        UserId::assert($actorId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT op.user_id FROM forwext_direct_conversation_participants p '
            . 'INNER JOIN forwext_direct_conversation_participants op '
            . 'ON op.conversation_id=p.conversation_id AND op.user_id<>p.user_id '
            . 'WHERE p.conversation_id=:conversation_id AND p.user_id=:actor_id AND p.left_at_utc IS NULL LIMIT 1',
            ['conversation_id'=>$conversationId->value(),'actor_id'=>$actorId->value()],
        ));
        return $row === null ? null : UserId::fromStored((string) $row['user_id']);
    }

    public function markRead(EntityId $actorId, EntityId $conversationId, DateTimeImmutable $at): void
    {
        UserId::assert($actorId);
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_direct_conversation_participants p '
            . 'INNER JOIN forwext_direct_conversations c ON c.conversation_id=p.conversation_id '
            . 'SET p.last_read_message_id=c.last_message_id,p.last_read_at_utc=:read_at '
            . 'WHERE p.conversation_id=:conversation_id AND p.user_id=:actor_id AND p.left_at_utc IS NULL',
            [
                'read_at'=>self::format(self::utc($at)),
                'conversation_id'=>$conversationId->value(),
                'actor_id'=>$actorId->value(),
            ],
        ));
    }

    public function setStarred(
        EntityId $actorId,
        EntityId $conversationId,
        bool $starred,
        DateTimeImmutable $at,
    ): bool {
        UserId::assert($actorId);
        if (!$this->activeParticipantExists($actorId, $conversationId)) {
            return false;
        }

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_direct_conversation_participants SET starred_at_utc='
            . ($starred ? ':starred_at' : 'NULL')
            . ' WHERE conversation_id=:conversation_id AND user_id=:actor_id AND left_at_utc IS NULL',
            $starred
                ? [
                    'starred_at'=>self::format(self::utc($at)),
                    'conversation_id'=>$conversationId->value(),
                    'actor_id'=>$actorId->value(),
                ]
                : [
                    'conversation_id'=>$conversationId->value(),
                    'actor_id'=>$actorId->value(),
                ],
        ));
        return true;
    }

    public function leave(EntityId $actorId, EntityId $conversationId, DateTimeImmutable $at): bool
    {
        UserId::assert($actorId);
        if (!$this->activeParticipantExists($actorId, $conversationId)) {
            return false;
        }

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_direct_conversation_participants '
            . 'SET left_at_utc=:left_at,starred_at_utc=NULL '
            . 'WHERE conversation_id=:conversation_id AND user_id=:actor_id AND left_at_utc IS NULL',
            [
                'left_at'=>self::format(self::utc($at)),
                'conversation_id'=>$conversationId->value(),
                'actor_id'=>$actorId->value(),
            ],
        ));
        return true;
    }

    public function reactivate(EntityId $actorId, EntityId $conversationId): bool
    {
        UserId::assert($actorId);
        $exists = $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_direct_conversation_participants '
            . 'WHERE conversation_id=:conversation_id AND user_id=:actor_id',
            ['conversation_id'=>$conversationId->value(),'actor_id'=>$actorId->value()],
        ));
        if ((int) $exists !== 1) {
            return false;
        }

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_direct_conversation_participants SET left_at_utc=NULL '
            . 'WHERE conversation_id=:conversation_id AND user_id=:actor_id',
            ['conversation_id'=>$conversationId->value(),'actor_id'=>$actorId->value()],
        ));
        return true;
    }

    private function activeParticipantExists(EntityId $actorId, EntityId $conversationId): bool
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_direct_conversation_participants '
            . 'WHERE conversation_id=:conversation_id AND user_id=:actor_id AND left_at_utc IS NULL',
            ['conversation_id'=>$conversationId->value(),'actor_id'=>$actorId->value()],
        )) === 1;
    }

    private function insertMessage(
        TransactionalQueryExecutor $database,
        EntityId $actorId,
        EntityId $conversationId,
        string $body,
        DateTimeImmutable $at,
    ): DirectMessage {
        $message = new DirectMessage(
            EntityId::generate(),
            $conversationId,
            $actorId,
            'self',
            $body,
            $at,
        );
        $database->execute(new CompiledQuery(
            'INSERT INTO forwext_direct_messages '
            . '(message_id,conversation_id,author_user_id,body,created_at_utc) '
            . 'VALUES (:message_id,:conversation_id,:author_user_id,:body,:created_at)',
            [
                'message_id'=>$message->messageId->value(),
                'conversation_id'=>$conversationId->value(),
                'author_user_id'=>$actorId->value(),
                'body'=>$body,
                'created_at'=>self::format($at),
            ],
            true,
        ));
        return $message;
    }

    private function touchConversation(
        TransactionalQueryExecutor $database,
        EntityId $conversationId,
        EntityId $messageId,
        DateTimeImmutable $at,
    ): void {
        $database->execute(new CompiledQuery(
            'UPDATE forwext_direct_conversations SET last_message_id=:message_id,updated_at_utc=:updated_at '
            . 'WHERE conversation_id=:conversation_id',
            [
                'message_id'=>$messageId->value(),
                'updated_at'=>self::format($at),
                'conversation_id'=>$conversationId->value(),
            ],
            true,
        ));
    }

    /** @param array<string,mixed> $row */
    private function hydrateSummary(array $row): DirectConversationSummary
    {
        return new DirectConversationSummary(
            EntityId::fromString((string) $row['conversation_id']),
            UserId::fromStored((string) $row['other_user_id']),
            (string) $row['other_username'],
            (string) $row['last_message_body'],
            self::parse((string) $row['updated_at_utc']),
            (int) $row['unread_count'],
            (bool) $row['starred'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateMessage(array $row): DirectMessage
    {
        $author = $row['author_user_id'] === null ? null : UserId::fromStored((string) $row['author_user_id']);
        return new DirectMessage(
            EntityId::fromString((string) $row['message_id']),
            EntityId::fromString((string) $row['conversation_id']),
            $author,
            (string) $row['author_username'],
            (string) $row['body'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    private static function pairKey(EntityId $leftUserId, EntityId $rightUserId): string
    {
        $ids = [$leftUserId->value(), $rightUserId->value()];
        sort($ids, SORT_STRING);
        return hash('sha256', implode(':', $ids));
    }

    private static function assertPage(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Direct conversation pagination is invalid.');
        }
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $value): string
    {
        return self::utc($value)->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored direct-conversation timestamp is invalid.');
        }
        return $time;
    }
}
