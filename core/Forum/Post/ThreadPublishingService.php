<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\ThreadCreationService;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;

final readonly class ThreadPublishingService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ThreadCreationService $threads,
        private PostService $posts,
    ) {
    }

    public function publish(
        EntityId $forumNodeId,
        ThreadTypeKey $typeKey,
        ThreadTitle $title,
        PostBody $firstPostBody,
        DateTimeImmutable $now,
    ): PublishedThread {
        return $this->database->transaction(function () use (
            $forumNodeId,
            $typeKey,
            $title,
            $firstPostBody,
            $now,
        ): PublishedThread {
            $thread = $this->threads->create($forumNodeId, $typeKey, $title, $now);
            $firstPost = $this->posts->createFirstPost($thread->id(), $firstPostBody, $now);
            return new PublishedThread($thread, $firstPost);
        });
    }
}
