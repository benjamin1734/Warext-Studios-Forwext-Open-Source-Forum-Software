<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;
use Forwext\Core\Moderation\Abuse\AbuseAction;
use Forwext\Core\Moderation\Abuse\AbuseContext;
use Forwext\Core\Moderation\Abuse\AbuseDecision;
use Forwext\Core\Moderation\Abuse\AbuseEngine;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use InvalidArgumentException;

final readonly class AbuseContentPipelineProcessor implements ContentPipelineProcessor, ContentPipelineAfterPersistProcessor
{
    public function __construct(private ?AbuseEngine $abuse = null)
    {
    }

    public function stage(): ContentPipelineStage
    {
        return ContentPipelineStage::Spam;
    }

    public function process(
        ContentPipelineContext $context,
        DateTimeImmutable $at,
    ): ContentPipelineContext {
        if ($this->abuse === null) {
            return $context->withAttribute('spam.action', AbuseAction::Allow->value);
        }

        $abuseContext = $this->context($context);
        if ($abuseContext === null) {
            return $context->withAttribute('spam.action', AbuseAction::Allow->value);
        }

        $decision = $this->abuse->evaluate($abuseContext, $at);
        if ($decision->isRejected()) {
            $this->abuse->record($abuseContext, $decision, null, null, $at);
            throw new ContentPipelineRejectedException('Content creation was blocked by anti-abuse policy.');
        }

        return $context
            ->requiringReview($context->requiresReview || $decision->requiresReview())
            ->withAttribute('spam.action', $decision->action->value)
            ->withAttribute('spam.matched_keys', implode(',', $decision->matchedKeys));
    }

    public function afterPersist(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
        if ($this->abuse === null || ($context->attributes['spam.action'] ?? null) !== AbuseAction::Review->value) {
            return;
        }

        $abuseContext = $this->context($context);
        if ($abuseContext === null) {
            return;
        }

        $matched = $context->attributes['spam.matched_keys'] ?? '';
        if (!is_string($matched)) {
            throw new InvalidArgumentException('Content pipeline spam matched keys are invalid.');
        }
        $keys = $matched === '' ? [] : explode(',', $matched);

        $this->abuse->record(
            $abuseContext,
            new AbuseDecision(AbuseAction::Review, $keys),
            $persisted->targetType,
            $persisted->targetId,
            $at,
        );
    }

    private function context(ContentPipelineContext $context): ?AbuseContext
    {
        $eventType = match ($context->contentType) {
            'forum.thread' => AbuseEventType::Thread,
            'forum.post' => AbuseEventType::Post,
            default => null,
        };
        if ($eventType === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($context->text)) ?? trim($context->text);
        return new AbuseContext(
            $eventType,
            $context->actorUserId,
            AbusePipelineAttributes::identity($context),
            AbusePipelineAttributes::ip($context),
            AbusePipelineAttributes::device($context),
            hash('sha256', $eventType->value . ':' . strtolower($normalized)),
        );
    }
}
