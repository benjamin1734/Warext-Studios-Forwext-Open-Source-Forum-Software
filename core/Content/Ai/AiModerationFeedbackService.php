<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AiModerationFeedbackService
{
    public function __construct(
        private AiModerationFeedbackStore $feedback,
        private PermissionGate $gate,
    ) {
    }

    public function report(
        EntityId $decisionId,
        string $kind,
        string $note,
        DateTimeImmutable $at,
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
        $this->feedback->record($item);
        return $item;
    }
}
