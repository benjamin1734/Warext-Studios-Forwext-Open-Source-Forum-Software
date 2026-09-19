<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseContentManagerRepository implements ContentManagerRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function search(ContentManagerFilter $filter, int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 200 || $offset < 0 || $offset > 10000) {
            throw new InvalidArgumentException('Content manager search pagination is invalid.');
        }

        $items = [];
        if ($filter->contentType !== ContentManagerContentType::Post) {
            foreach ($this->threadRows($filter, min(200, $limit + $offset)) as $row) {
                $items[] = $this->threadItem($row);
            }
        }
        if ($filter->contentType !== ContentManagerContentType::Thread) {
            foreach ($this->postRows($filter, min(200, $limit + $offset)) as $row) {
                $items[] = $this->postItem($row);
            }
        }

        usort(
            $items,
            static fn (ContentManagerItem $a, ContentManagerItem $b): int =>
                $b->updatedAt <=> $a->updatedAt ?: strcmp($b->id->value(), $a->id->value()),
        );
        return array_slice($items, $offset, $limit);
    }

    public function targets(ContentManagerFilter $filter, int $limit = 501): array
    {
        if ($limit < 1 || $limit > 5001) {
            throw new InvalidArgumentException('Content manager target limit is invalid.');
        }

        $targets = [];
        if ($filter->contentType !== ContentManagerContentType::Post) {
            foreach ($this->threadRows($filter, $limit) as $row) {
                $targets[] = new ContentManagerTarget(
                    ContentManagerContentType::Thread,
                    EntityId::fromString((string) $row['content_id']),
                    EntityId::fromString((string) $row['forum_node_id']),
                    (string) $row['moderation_state'],
                    (bool) $row['deleted'],
                );
            }
        }
        if ($filter->contentType !== ContentManagerContentType::Thread && count($targets) < $limit) {
            foreach ($this->postRows($filter, $limit) as $row) {
                $targets[] = new ContentManagerTarget(
                    ContentManagerContentType::Post,
                    EntityId::fromString((string) $row['content_id']),
                    EntityId::fromString((string) $row['forum_node_id']),
                    (string) $row['moderation_state'],
                    (bool) $row['deleted'],
                );
            }
        }
        return array_slice($targets, 0, $limit);
    }

    public function current(ContentManagerContentType $type, EntityId $id): ?ContentManagerTarget
    {
        $row = $type === ContentManagerContentType::Thread
            ? $this->database->fetchOne(new CompiledQuery(
                'SELECT t.thread_id AS content_id,t.forum_node_id,t.moderation_state,t.deleted '
                . 'FROM forwext_threads t WHERE t.thread_id=:id LIMIT 1',
                ['id'=>$id->value()],
            ))
            : $this->database->fetchOne(new CompiledQuery(
                'SELECT p.post_id AS content_id,t.forum_node_id,p.moderation_state,p.deleted '
                . 'FROM forwext_posts p INNER JOIN forwext_threads t ON t.thread_id=p.thread_id '
                . 'WHERE p.post_id=:id LIMIT 1',
                ['id'=>$id->value()],
            ));
        if ($row === null) {
            return null;
        }
        return new ContentManagerTarget(
            $type,
            EntityId::fromString((string) $row['content_id']),
            EntityId::fromString((string) $row['forum_node_id']),
            (string) $row['moderation_state'],
            (bool) $row['deleted'],
        );
    }

    public function source(ContentManagerContentType $type, EntityId $id): ?string
    {
        if ($type === ContentManagerContentType::Post) {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT body_source FROM forwext_posts WHERE post_id=:id LIMIT 1',
                ['id'=>$id->value()],
            ));
            return is_string($row['body_source'] ?? null) ? (string) $row['body_source'] : null;
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT t.title,p.body_source FROM forwext_threads t '
            . 'LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id AND p.position=1 '
            . 'WHERE t.thread_id=:id LIMIT 1',
            ['id'=>$id->value()],
        ));
        if ($row === null || !is_string($row['title'] ?? null)) {
            return null;
        }
        $body = is_string($row['body_source'] ?? null) ? (string) $row['body_source'] : '';
        return trim((string) $row['title'] . ($body === '' ? '' : "\n" . $body));
    }

    public function postIdsForThread(EntityId $threadId, int $limit = 10000): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Content manager related post limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT post_id FROM forwext_posts WHERE thread_id=:thread_id ORDER BY position ASC LIMIT ' . $limit,
            ['thread_id'=>$threadId->value()],
        ));
        $ids = [];
        foreach ($rows as $row) {
            if (!is_string($row['post_id'] ?? null)) {
                throw new ContentManagerOperationException('Stored related post id is malformed.');
            }
            $ids[] = EntityId::fromString((string) $row['post_id']);
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function threadRows(ContentManagerFilter $filter, int $limit): array
    {
        [$conditions, $parameters] = $this->conditions($filter, 't', 't.title');
        return $this->database->fetchAll(new CompiledQuery(
            'SELECT t.thread_id AS content_id,t.forum_node_id,t.author_user_id,t.title,'
            . 'COALESCE(p.body_source,\'\') AS excerpt,t.moderation_state,t.deleted,'
            . 't.created_at_utc,t.updated_at_utc,NULL AS thread_id '
            . 'FROM forwext_threads t LEFT JOIN forwext_posts p '
            . 'ON p.thread_id=t.thread_id AND p.position=1 '
            . 'WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY t.updated_at_utc DESC,t.thread_id DESC LIMIT ' . $limit,
            $parameters,
        ));
    }

    /** @return list<array<string,mixed>> */
    private function postRows(ContentManagerFilter $filter, int $limit): array
    {
        [$conditions, $parameters] = $this->conditions($filter, 'p', 'p.body_source', 't.forum_node_id');
        return $this->database->fetchAll(new CompiledQuery(
            'SELECT p.post_id AS content_id,t.forum_node_id,p.author_user_id,t.title,'
            . 'p.body_source AS excerpt,p.moderation_state,p.deleted,p.created_at_utc,p.updated_at_utc,p.thread_id '
            . 'FROM forwext_posts p INNER JOIN forwext_threads t ON t.thread_id=p.thread_id '
            . 'WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY p.updated_at_utc DESC,p.post_id DESC LIMIT ' . $limit,
            $parameters,
        ));
    }

    /**
     * @return array{0:list<string>,1:array<string,string|int|float|bool|null>}
     */
    private function conditions(
        ContentManagerFilter $filter,
        string $alias,
        string $textColumn,
        ?string $forumColumn = null,
    ): array {
        $forumColumn ??= $alias . '.forum_node_id';
        $conditions = [$alias . '.author_user_id=:target_user_id'];
        $parameters = ['target_user_id'=>$filter->targetUserId->value()];

        if ($filter->forumNodeId !== null) {
            $conditions[] = $forumColumn . '=:forum_node_id';
            $parameters['forum_node_id'] = $filter->forumNodeId->value();
        }
        if ($filter->moderationState !== null) {
            $conditions[] = $alias . '.moderation_state=:moderation_state';
            $parameters['moderation_state'] = $filter->moderationState;
        }
        if ($filter->deleted !== null) {
            $conditions[] = $alias . '.deleted=:deleted';
            $parameters['deleted'] = $filter->deleted ? 1 : 0;
        }
        $query = $filter->normalizedQuery();
        if ($query !== null) {
            $conditions[] = 'INSTR(LOWER(' . $textColumn . '),LOWER(:query))>0';
            $parameters['query'] = $query;
        }
        return [$conditions, $parameters];
    }

    /** @param array<string,mixed> $row */
    private function threadItem(array $row): ContentManagerItem
    {
        return $this->item(ContentManagerContentType::Thread, $row);
    }

    /** @param array<string,mixed> $row */
    private function postItem(array $row): ContentManagerItem
    {
        return $this->item(ContentManagerContentType::Post, $row);
    }

    /** @param array<string,mixed> $row */
    private function item(ContentManagerContentType $type, array $row): ContentManagerItem
    {
        foreach (['content_id','forum_node_id','author_user_id','title','excerpt','moderation_state','created_at_utc','updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new ContentManagerOperationException('Stored content manager row is malformed.');
            }
        }
        $excerpt = trim((string) $row['excerpt']);
        if (strlen($excerpt) > 500) {
            $excerpt = substr($excerpt, 0, 497) . '...';
        }
        return new ContentManagerItem(
            $type,
            EntityId::fromString((string) $row['content_id']),
            EntityId::fromString((string) $row['author_user_id']),
            EntityId::fromString((string) $row['forum_node_id']),
            $type === ContentManagerContentType::Post && is_string($row['thread_id'] ?? null)
                ? EntityId::fromString((string) $row['thread_id'])
                : null,
            (string) $row['title'],
            $excerpt,
            (string) $row['moderation_state'],
            (bool) $row['deleted'],
            $this->date((string) $row['created_at_utc']),
            $this->date((string) $row['updated_at_utc']),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new ContentManagerOperationException('Stored content manager timestamp is invalid.');
        }
        return $date;
    }
}
