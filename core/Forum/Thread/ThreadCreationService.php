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

final readonly class ThreadCreationService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ThreadTypeRegistry $types,
        private PermissionGate $gate,
    ) {
    }

    public function create(
        EntityId $forumNodeId,
        ThreadTypeKey $typeKey,
        ThreadTitle $title,
        DateTimeImmutable $now,
    ): Thread {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $node = $hierarchy->find($forumNodeId);
        if ($node === null || $node->type() !== ForumNodeType::Forum) {
            throw new ThreadOperationException('Thread target is not an available forum.');
        }

        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumNodeId);
        $this->gate->require(ThreadPermission::Create->key(), $forumNodeId);

        $settings = $node->forumSettings();
        if ($settings === null || !$settings->allowNewThreads()) {
            throw new ThreadOperationException('New threads are disabled for this forum.');
        }

        $type = $this->types->require($typeKey);
        $thread = Thread::create(
            ThreadId::generate(),
            $forumNodeId,
            $this->gate->actorId(),
            $type->key(),
            $title,
            $settings->requireThreadApproval(),
            $now,
        );
        $this->threads->save($thread);

        return $thread;
    }
}
