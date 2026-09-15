<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;

final readonly class ThreadStateService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private PermissionGate $gate,
    ) {
    }

    public function lock(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Lock, static fn (Thread $thread): bool => $thread->lock($at));
    }

    public function unlock(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Lock, static fn (Thread $thread): bool => $thread->unlock($at));
    }

    public function stick(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Sticky, static fn (Thread $thread): bool => $thread->stick($at));
    }

    public function unstick(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Sticky, static fn (Thread $thread): bool => $thread->unstick($at));
    }

    public function feature(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Feature, static fn (Thread $thread): bool => $thread->feature($at));
    }

    public function unfeature(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Feature, static fn (Thread $thread): bool => $thread->unfeature($at));
    }

    public function approve(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Moderate, static fn (Thread $thread): bool => $thread->approve($at));
    }

    public function reject(EntityId $threadId, DateTimeImmutable $at): Thread
    {
        return $this->mutate($threadId, ThreadPermission::Moderate, static fn (Thread $thread): bool => $thread->reject($at));
    }

    /** @param callable(Thread): bool $mutation */
    private function mutate(
        EntityId $threadId,
        ThreadPermission $permission,
        callable $mutation,
    ): Thread {
        $thread = $this->threads->find($threadId)
            ?? throw new ThreadOperationException('Thread is not available.');

        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new ThreadOperationException('Thread forum is not available.');
        }

        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->gate->require($permission->key(), $forum->id());

        if ($mutation($thread)) {
            $this->threads->save($thread);
        }

        return $thread;
    }
}
