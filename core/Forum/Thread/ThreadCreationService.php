<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\AbusePipelineAttributes;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ContentPipelineRejectedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Moderation\Abuse\AbuseContentContext;
use Forwext\Core\Moderation\Abuse\AbuseContext;
use Forwext\Core\Moderation\Abuse\AbuseDecision;
use Forwext\Core\Moderation\Abuse\AbuseEngine;

final readonly class ThreadCreationService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ThreadTypeRegistry $types,
        private PermissionGate $gate,
        private ?AbuseEngine $abuse = null,
        private ?ContentPipeline $pipeline = null,
    ) {
    }

    public function create(
        EntityId $forumNodeId,
        ThreadTypeKey $typeKey,
        ThreadTitle $title,
        DateTimeImmutable $now,
        ?AbuseContext $abuseContext = null,
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
        if ($this->pipeline !== null) {
            $attributes = AbusePipelineAttributes::fromRequestContext(
                $abuseContext,
                \Forwext\Core\Moderation\Abuse\AbuseEventType::Thread,
                $this->gate->actorId(),
            );
            $attributes['forum.node_id'] = $forumNodeId->value();
            try {
                $created = $this->pipeline->execute(
                    new ContentPipelineContext(
                        $this->gate->actorId(),
                        'forum.thread',
                        $title->value(),
                        200,
                        $settings->requireThreadApproval(),
                        $attributes,
                    ),
                    $now,
                    function (ContentPipelineContext $context) use ($forumNodeId, $type, $now): ContentPipelinePersisted {
                        $thread = Thread::create(
                            ThreadId::generate(),
                            $forumNodeId,
                            $this->gate->actorId(),
                            $type->key(),
                            ThreadTitle::fromString($context->text),
                            $context->requiresReview,
                            $now,
                        );
                        $this->threads->save($thread);
                        return new ContentPipelinePersisted($thread, 'forum.thread', $thread->id());
                    },
                );
            } catch (ContentPipelineRejectedException $exception) {
                throw new ThreadOperationException(
                    'Thread creation was blocked by content policy.',
                    previous: $exception,
                );
            }
            if (!$created instanceof Thread) {
                throw new ThreadOperationException('Thread content pipeline returned an invalid result.');
            }
            return $created;
        }

        $decision = AbuseDecision::allow();
        $context = null;
        if ($this->abuse !== null) {
            $context = AbuseContentContext::thread($this->gate->actorId(), $title->value(), $abuseContext);
            $decision = $this->abuse->evaluate($context, $now);
            if ($decision->isRejected()) {
                $this->abuse->record($context, $decision, null, null, $now);
                throw new ThreadOperationException('Thread creation was blocked by anti-abuse policy.');
            }
        }

        $thread = Thread::create(
            ThreadId::generate(),
            $forumNodeId,
            $this->gate->actorId(),
            $type->key(),
            $title,
            $settings->requireThreadApproval() || $decision->requiresReview(),
            $now,
        );
        $this->threads->save($thread);

        if ($this->abuse !== null && $context !== null && $decision->requiresReview()) {
            $this->abuse->record($context, $decision, 'forum.thread', $thread->id(), $now);
        }

        return $thread;
    }
}
