<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

interface ModerationAuditStore
{
    public function append(ModerationAuditEvent $event): void;

    /** @return list<ModerationAuditEvent> */
    public function recentForTarget(string $targetType, string $targetId, int $limit = 100): array;
}
