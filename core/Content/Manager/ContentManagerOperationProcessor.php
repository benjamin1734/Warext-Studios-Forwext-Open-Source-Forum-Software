<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Forum\Moderation\ContentModerationRepository;
use Forwext\Core\Forum\Moderation\ModerationAuditContext;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use Throwable;

final readonly class ContentManagerOperationProcessor
{
    public function __construct(
        private ContentManagerOperationRepository $operations,
        private ContentManagerRepository $content,
        private ContentModerationRepository $moderation,
        private SearchIndexChangeStore $searchChanges,
        private ContentPipeline $pipeline,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function process(
        \Forwext\Core\Domain\Entity\EntityId $operationId,
        DateTimeImmutable $at,
        int $limit = 50,
    ): ContentManagerOperation {
        $operation = $this->operations->find($operationId)
            ?? throw new ContentManagerOperationException('Content manager operation is unavailable.');
        if ($operation->status->terminal()) {
            return $operation;
        }
        if ($operation->actorUserId === null
            || !$this->authorizer->allows(
                $operation->actorUserId,
                PermissionKey::fromString('content_manager.execute'),
            )
        ) {
            throw new ContentManagerAccessDeniedException('Content manager execute permission is no longer available.');
        }

        $items = $this->operations->claim($operationId, $limit, $at);
        foreach ($items as $item) {
            try {
                $outcome = $this->processItem($operation, $item, $at);
                $this->operations->completeItem(
                    $operationId,
                    $item->type,
                    $item->contentId,
                    $outcome,
                    null,
                    $at,
                );
            } catch (Throwable $exception) {
                $this->operations->completeItem(
                    $operationId,
                    $item->type,
                    $item->contentId,
                    ContentManagerItemStatus::Failed,
                    $this->failureCode($exception),
                    $at,
                );
            }
        }
        return $this->operations->refreshProgress($operationId, $at);
    }

    private function processItem(
        ContentManagerOperation $operation,
        ContentManagerOperationItem $item,
        DateTimeImmutable $at,
    ): ContentManagerItemStatus {
        $current = $this->content->current($item->type, $item->contentId);
        if ($current === null) {
            return ContentManagerItemStatus::Skipped;
        }

        $actor = $operation->actorUserId
            ?? throw new ContentManagerAccessDeniedException('Content manager actor is unavailable.');
        $context = new ModerationAuditContext(
            $actor,
            ModerationReasonCode::fromString('content_manager.' . $operation->action->value),
            ModerationRequestId::fromString('content-manager:' . $operation->operationId->value()),
            $at,
        );

        switch ($operation->action) {
            case ContentManagerAction::Delete:
                if ($current->deleted) {
                    return ContentManagerItemStatus::Skipped;
                }
                if ($item->type === ContentManagerContentType::Thread) {
                    $this->moderation->setThreadDeleted($item->contentId, true, $context);
                    $this->reindexThreadTree($item->contentId);
                } else {
                    $this->moderation->setPostDeleted($item->contentId, true, $context);
                    $this->searchChanges->record('post', $item->contentId->value());
                }
                return ContentManagerItemStatus::Succeeded;

            case ContentManagerAction::Restore:
                if (!$current->deleted) {
                    return ContentManagerItemStatus::Skipped;
                }
                if ($item->type === ContentManagerContentType::Thread) {
                    $this->moderation->setThreadDeleted($item->contentId, false, $context);
                    $this->reindexThreadTree($item->contentId);
                } else {
                    $this->moderation->setPostDeleted($item->contentId, false, $context);
                    $this->searchChanges->record('post', $item->contentId->value());
                }
                return ContentManagerItemStatus::Succeeded;

            case ContentManagerAction::Approve:
                if ($current->moderationState === 'visible') {
                    return ContentManagerItemStatus::Skipped;
                }
                if ($item->type === ContentManagerContentType::Thread) {
                    $this->moderation->approveThread($item->contentId, $context);
                    $this->reindexThreadTree($item->contentId);
                } else {
                    $this->moderation->approvePost($item->contentId, $context);
                    $this->searchChanges->record('post', $item->contentId->value());
                }
                return ContentManagerItemStatus::Succeeded;

            case ContentManagerAction::Move:
                if ($item->type !== ContentManagerContentType::Thread || $operation->targetForumNodeId === null) {
                    throw new ContentManagerOperationException('Content manager move target is invalid.');
                }
                if ($current->forumNodeId->equals($operation->targetForumNodeId)) {
                    return ContentManagerItemStatus::Skipped;
                }
                $this->moderation->moveThread($item->contentId, $operation->targetForumNodeId, $context);
                $this->reindexThreadTree($item->contentId);
                return ContentManagerItemStatus::Succeeded;

            case ContentManagerAction::Reindex:
                if ($item->type === ContentManagerContentType::Thread) {
                    $this->reindexThreadTree($item->contentId);
                } else {
                    $this->searchChanges->record('post', $item->contentId->value());
                }
                return ContentManagerItemStatus::Succeeded;

            case ContentManagerAction::Reprocess:
                $source = $this->content->source($item->type, $item->contentId);
                if ($source === null || trim($source) === '') {
                    return ContentManagerItemStatus::Skipped;
                }
                $this->pipeline->preprocess(
                    new ContentPipelineContext(
                        $actor,
                        'forum.' . $item->type->value,
                        $source,
                        1_000_000,
                        false,
                        ['forum.node_id'=>$current->forumNodeId->value()],
                    ),
                    $at,
                );
                if ($item->type === ContentManagerContentType::Thread) {
                    $this->reindexThreadTree($item->contentId);
                } else {
                    $this->searchChanges->record('post', $item->contentId->value());
                }
                return ContentManagerItemStatus::Succeeded;
        }
    }

    private function reindexThreadTree(\Forwext\Core\Domain\Entity\EntityId $threadId): void
    {
        $this->searchChanges->record('thread', $threadId->value());
        foreach ($this->content->postIdsForThread($threadId, 10000) as $postId) {
            $this->searchChanges->record('post', $postId->value());
        }
    }

    private function failureCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ContentManagerAccessDeniedException => 'permission_denied',
            $exception instanceof ContentManagerOperationException => 'operation_invalid',
            default => 'operation_failed',
        };
    }
}
