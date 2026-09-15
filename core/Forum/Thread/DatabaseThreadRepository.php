<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseThreadRepository implements ThreadRepository
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ThreadTypeRegistry $types,
    ) {
    }

    public function find(EntityId $threadId): ?Thread
    {
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE `thread_id` = :thread_id LIMIT 1',
            ['thread_id' => $threadId->value()],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array
    {
        ForumNodeId::assert($forumNodeId);
        if ($limit < 1 || $limit > 200 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Thread pagination is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql()
            . ' WHERE `forum_node_id` = :forum_node_id '
            . 'ORDER BY `sticky` DESC, `featured` DESC, `updated_at_utc` DESC, `thread_id` DESC '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['forum_node_id' => $forumNodeId->value()],
        ));

        return array_map($this->hydrate(...), $rows);
    }

    public function save(Thread $thread): void
    {
        $this->types->require($thread->typeKey());

        $newVersion = $this->database->transaction(
            function (TransactionalQueryExecutor $database) use ($thread): int {
                $expectedVersion = $thread->version();
                $newVersion = $expectedVersion + 1;
                $parameters = [
                    'thread_id' => $thread->id()->value(),
                    'forum_node_id' => $thread->forumNodeId()->value(),
                    'author_user_id' => $thread->authorUserId()?->value(),
                    'type_key' => $thread->typeKey()->value(),
                    'title' => $thread->title()->value(),
                    'moderation_state' => $thread->moderationState()->value,
                    'locked' => $thread->isLocked(),
                    'sticky' => $thread->isSticky(),
                    'featured' => $thread->isFeatured(),
                    'version' => $newVersion,
                    'created_at' => self::format($thread->createdAt()),
                    'updated_at' => self::format($thread->updatedAt()),
                ];

                if ($expectedVersion === 0) {
                    $affected = $database->execute(new CompiledQuery(
                        'INSERT INTO `forwext_threads` '
                        . '(`thread_id`, `forum_node_id`, `author_user_id`, `type_key`, `title`, '
                        . '`moderation_state`, `locked`, `sticky`, `featured`, `version`, '
                        . '`created_at_utc`, `updated_at_utc`) '
                        . 'VALUES (:thread_id, :forum_node_id, :author_user_id, :type_key, :title, '
                        . ':moderation_state, :locked, :sticky, :featured, :version, '
                        . ':created_at, :updated_at)',
                        $parameters,
                    ));
                } else {
                    $parameters['expected_version'] = $expectedVersion;
                    $affected = $database->execute(new CompiledQuery(
                        'UPDATE `forwext_threads` SET '
                        . '`forum_node_id` = :forum_node_id, `author_user_id` = :author_user_id, '
                        . '`type_key` = :type_key, `title` = :title, '
                        . '`moderation_state` = :moderation_state, `locked` = :locked, '
                        . '`sticky` = :sticky, `featured` = :featured, `version` = :version, '
                        . '`updated_at_utc` = :updated_at '
                        . 'WHERE `thread_id` = :thread_id AND `version` = :expected_version',
                        $parameters,
                    ));
                }

                if ($affected !== 1) {
                    throw new ThreadConcurrencyException(
                        'Thread persistence failed because the stored version changed.',
                    );
                }

                return $newVersion;
            },
        );

        $thread->markPersisted($newVersion);
    }

    private function selectSql(): string
    {
        return 'SELECT `thread_id`, `forum_node_id`, `author_user_id`, `type_key`, `title`, '
            . '`moderation_state`, `locked`, `sticky`, `featured`, `version`, '
            . '`created_at_utc`, `updated_at_utc` FROM `forwext_threads`';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Thread
    {
        foreach ([
            'thread_id',
            'forum_node_id',
            'type_key',
            'title',
            'moderation_state',
            'locked',
            'sticky',
            'featured',
            'version',
            'created_at_utc',
            'updated_at_utc',
        ] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new RuntimeException('Stored thread row is missing required data.');
            }
        }

        $typeKey = ThreadTypeKey::fromString((string) $row['type_key']);
        $this->types->require($typeKey);
        $author = null;
        if (($row['author_user_id'] ?? null) !== null) {
            $author = UserId::fromStored((string) $row['author_user_id']);
        }

        return Thread::hydrate(
            ThreadId::fromStored((string) $row['thread_id']),
            ForumNodeId::fromStored((string) $row['forum_node_id']),
            $author,
            $typeKey,
            ThreadTitle::fromString((string) $row['title']),
            ThreadModerationState::from((string) $row['moderation_state']),
            (bool) $row['locked'],
            (bool) $row['sticky'],
            (bool) $row['featured'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            (int) $row['version'],
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored thread timestamp is invalid.');
        }

        return $parsed;
    }
}
