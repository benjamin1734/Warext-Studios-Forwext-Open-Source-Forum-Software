<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use LogicException;

final readonly class AiModerationFeedbackService
{
    public function __construct(
        private AiModerationFeedbackStore $feedback,
        private PermissionGate $gate,
        private ?AuditRecorder $audit = null,
    ) {
    }

    public function report(
        EntityId $decisionId,
        string $kind,
        string $note,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): AiModerationFeedback {
        $this->gate->require(PermissionKey::fromString('ai.manage'));
        $item = new AiModerationFeedback(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $decisionId,
            $this->gate->actorId(),
            $kind,
            $note,
            $at,
        );
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Moderation,
            $this->gate->actorId(),
            AuditAction::fromString('content.ai_moderation.feedback.record'),
            'ai.moderation_decision',
            $decisionId->value(),
            null,
            null,
            $requestId ?? AuditRequestId::generate(),
            [],
            [
                'feedback_id'=>$item->feedbackId->value(),
                'kind'=>$item->kind,
            ],
            $at,
        );

        return $this->auditRecorder()->mutate($event, function () use ($item): AiModerationFeedback {
            $this->feedback->record($item);
            return $item;
        });
    }

    private function auditRecorder(): AuditRecorder
    {
        return $this->audit ?? throw new LogicException('AI moderation feedback mutations require central audit.');
    }
}
