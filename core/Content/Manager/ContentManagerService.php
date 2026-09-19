<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use JsonException;
use LogicException;

final readonly class ContentManagerService
{
    public const JOB_TYPE = 'content.manager.execute';

    public function __construct(
        private ContentManagerRepository $content,
        private ContentManagerOperationRepository $operations,
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
        private QueueDriver $queue,
        private ?AuditRecorder $audit = null,
    ) {
    }

    /** @return list<ContentManagerItem> */
    public function search(EntityId $actorUserId, ContentManagerFilter $filter, int $limit = 100, int $offset = 0): array
    {
        $this->requirePermission($actorUserId, 'content_manager.access');
        return $this->content->search($filter, $limit, $offset);
    }

    public function preview(
        EntityId $actorUserId,
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId = null,
    ): ContentManagerPreview {
        $this->requirePermission($actorUserId, 'content_manager.execute');
        $this->validateAction($filter, $action, $targetForumNodeId);

        $targets = $this->content->targets($filter, 5001);
        $truncated = count($targets) > 5000;
        if ($truncated) {
            $targets = array_slice($targets, 0, 5000);
        }
        return new ContentManagerPreview(
            $action,
            $targets,
            $targetForumNodeId?->value(),
            $truncated,
        );
    }

    public function enqueue(
        EntityId $actorUserId,
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): ContentManagerOperation {
        $preview = $this->preview($actorUserId, $filter, $action, $targetForumNodeId);
        if ($preview->truncated) {
            throw new ContentManagerOperationException(
                'Content manager operation exceeds the 5000-target safety boundary; narrow the filter.',
            );
        }
        if ($preview->targets === []) {
            throw new ContentManagerOperationException('Content manager operation has no matching targets.');
        }

        $operationId = EntityId::fromString(bin2hex(random_bytes(16)));
        try {
            $payload = json_encode(
                ['operation_id'=>$operationId->value()],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new ContentManagerOperationException('Content manager queue payload could not be encoded.', previous:$exception);
        }

        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Moderation,
            $actorUserId,
            AuditAction::fromString('content.manager.enqueue'),
            'content.manager_operation',
            $operationId->value(),
            $filter->forumNodeId,
            null,
            $requestId ?? AuditRequestId::generate(),
            [],
            [
                'action'=>$action->value,
                'target_user_id'=>$filter->targetUserId->value(),
                'content_type'=>$filter->contentType?->value,
                'filter_forum_node_id'=>$filter->forumNodeId?->value(),
                'target_forum_node_id'=>$targetForumNodeId?->value(),
                'target_count'=>$preview->total(),
            ],
            $at,
        );

        return $this->auditRecorder()->mutate($event, function () use (
            $operationId,
            $actorUserId,
            $filter,
            $action,
            $targetForumNodeId,
            $preview,
            $payload,
            $at,
        ): ContentManagerOperation {
            $this->operations->create(
                $operationId,
                $actorUserId,
                $filter,
                $action,
                $targetForumNodeId,
                $preview->targets,
                $at,
            );
            $this->queue->push(
                QueueName::fromString('content-manager'),
                self::JOB_TYPE,
                $payload,
                5,
                $at,
            );

            return $this->operations->find($operationId)
                ?? throw new ContentManagerOperationException('Queued content manager operation could not be reloaded.');
        });
    }

    public function operation(EntityId $actorUserId, EntityId $operationId): ContentManagerOperation
    {
        $this->requirePermission($actorUserId, 'content_manager.access');
        $operation = $this->operations->find($operationId)
            ?? throw new ContentManagerOperationException('Content manager operation is unavailable.');
        if ($operation->actorUserId === null || !$operation->actorUserId->equals($actorUserId)) {
            throw new ContentManagerAccessDeniedException('Content manager operation belongs to another actor.');
        }
        return $operation;
    }

    /** @return list<ContentManagerOperationItem> */
    public function operationItems(EntityId $actorUserId, EntityId $operationId, int $limit = 200): array
    {
        $this->operation($actorUserId, $operationId);
        return $this->operations->items($operationId, $limit);
    }

    /** @return list<ContentManagerOperation> */
    public function recent(EntityId $actorUserId, int $limit = 20): array
    {
        $this->requirePermission($actorUserId, 'content_manager.access');
        return $this->operations->recentForActor($actorUserId, $limit);
    }

    public function requireExecute(EntityId $actorUserId): void
    {
        $this->requirePermission($actorUserId, 'content_manager.execute');
    }

    private function validateAction(
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId,
    ): void {
        if ($action === ContentManagerAction::Move) {
            if ($filter->contentType !== ContentManagerContentType::Thread) {
                throw new ContentManagerOperationException('Bulk move requires the content type filter to be thread.');
            }
            if ($targetForumNodeId === null) {
                throw new ContentManagerOperationException('Bulk move requires a target forum.');
            }
            $node = $this->nodes->find($targetForumNodeId);
            if ($node === null || $node->type() !== ForumNodeType::Forum) {
                throw new ContentManagerOperationException('Bulk move target must be an existing forum node.');
            }
            return;
        }

        if ($targetForumNodeId !== null) {
            throw new ContentManagerOperationException('Target forum is only valid for the move action.');
        }
    }

    private function requirePermission(EntityId $actorUserId, string $key): void
    {
        if (!$this->authorizer->allows($actorUserId, PermissionKey::fromString($key))) {
            throw new ContentManagerAccessDeniedException('Content manager permission is denied.');
        }
    }
    private function auditRecorder(): AuditRecorder
    {
        return $this->audit ?? throw new LogicException('Content manager mutations require central audit.');
    }

}
