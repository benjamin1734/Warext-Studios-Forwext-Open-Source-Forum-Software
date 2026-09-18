<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class ThreadSupportContextResolver implements SupportContextResolver
{
    public function __construct(
        private ThreadRepository $threads,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function type(): SupportContextType
    {
        return SupportContextType::Thread;
    }

    public function resolve(EntityId $actorUserId, EntityId $targetId): SupportContextLink
    {
        $thread = $this->threads->find($targetId)
            ?? throw new SupportContextUnavailableException('Linked thread is unavailable.');
        if (!$this->authorizer->allows(
            $actorUserId,
            PermissionKey::fromString('forum.view'),
            $thread->forumNodeId(),
        )) {
            throw new SupportContextUnavailableException('Linked thread is unavailable.');
        }

        return new SupportContextLink(
            SupportContextType::Thread,
            $thread->id(),
            $thread->title()->value(),
        );
    }
}
