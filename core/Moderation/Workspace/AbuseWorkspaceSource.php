<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Moderation\Abuse\AbuseEvent;
use Forwext\Core\Moderation\Abuse\AbuseRepository;

final readonly class AbuseWorkspaceSource implements ModerationWorkspaceSource
{
    public function __construct(
        private AbuseRepository $repository,
        private PermissionGate $gate,
    ) {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Abuse;
    }

    public function count(): int
    {
        return $this->canView() ? $this->repository->unresolvedCount() : 0;
    }

    public function latest(int $limit): array
    {
        if (!$this->canView()) {
            return [];
        }
        return array_map(
            static fn (AbuseEvent $event): ModerationWorkspaceItem => new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Abuse,
                'abuse.' . $event->eventType->value,
                $event->eventId->value(),
                $event->decision->value === 'reject' ? 'Otomatik engel' : 'İnceleme gerekli',
                'pending',
                $event->occurredAt,
                implode(', ', $event->matchedRuleKeys),
                '/moderation/abuse',
            ),
            $this->repository->unresolved($limit),
        );
    }

    private function canView(): bool
    {
        return $this->gate->allows(PermissionKey::fromString('moderation.abuse.view'));
    }
}
