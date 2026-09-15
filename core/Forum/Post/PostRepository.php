<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface PostRepository
{
    public function find(EntityId $postId): ?Post;

    public function firstPost(EntityId $threadId): ?Post;

    public function create(
        EntityId $threadId,
        EntityId $authorUserId,
        PostBody $body,
        bool $requiresApproval,
        bool $mustBeFirst,
        DateTimeImmutable $now,
    ): Post;

    public function save(Post $post): void;

    /** @return list<PostHistoryEntry> */
    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array;

    public function pageByThread(
        EntityId $threadId,
        int $page = 1,
        int $perPage = 20,
        bool $includeDeleted = false,
        bool $includeNonVisible = false,
    ): PostPage;

    public function counters(EntityId $threadId): PostCounters;
}
