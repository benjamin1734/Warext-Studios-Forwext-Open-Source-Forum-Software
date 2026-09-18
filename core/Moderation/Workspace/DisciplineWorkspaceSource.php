<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Moderation\Discipline\DisciplineAction;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineRepository;
use Forwext\Core\Moderation\Discipline\DisciplineRestrictionKey;
use InvalidArgumentException;

final readonly class DisciplineWorkspaceSource implements ModerationWorkspaceSource
{
    /** @param list<DisciplineActionType> $types */
    public function __construct(
        private DisciplineRepository $discipline,
        private PermissionGate $gate,
        private ModerationWorkspaceSection $section,
        private array $types,
        private Clock $clock = new SystemClock(),
    ) {
        if (!in_array($this->section, [ModerationWorkspaceSection::Warnings, ModerationWorkspaceSection::Bans], true)) {
            throw new InvalidArgumentException('Discipline workspace source section is invalid.');
        }
        if ($this->types === []) {
            throw new InvalidArgumentException('Discipline workspace source requires at least one action type.');
        }
        foreach ($this->types as $type) {
            if (!$type instanceof DisciplineActionType) {
                throw new InvalidArgumentException('Discipline workspace source action type is invalid.');
            }
        }
    }

    public function section(): ModerationWorkspaceSection
    {
        return $this->section;
    }

    public function count(): int
    {
        if (!$this->canView()) {
            return 0;
        }
        return $this->discipline->activeCount($this->types, $this->clock->now());
    }

    public function latest(int $limit): array
    {
        if (!$this->canView()) {
            return [];
        }
        return array_map($this->item(...), $this->discipline->recent($this->types, $limit));
    }

    private function item(DisciplineAction $action): ModerationWorkspaceItem
    {
        $summary = 'Kullanıcı: ' . $action->userId->value() . ' · Neden: ' . $action->reasonCode->value();
        if ($action->type === DisciplineActionType::Warning) {
            $summary .= ' · Puan: ' . $action->points;
        }
        if ($action->type === DisciplineActionType::Restriction) {
            $summary .= ' · ' . implode(', ', array_map(
                static fn (DisciplineRestrictionKey $key): string => $key->label(),
                $action->restrictions,
            ));
        }
        if ($action->expiresAt !== null) {
            $summary .= ' · Bitiş: ' . $action->expiresAt->format('Y-m-d H:i') . ' UTC';
        } elseif (in_array($action->type, [DisciplineActionType::Restriction, DisciplineActionType::Ban], true)) {
            $summary .= ' · Kalıcı';
        }

        return new ModerationWorkspaceItem(
            $this->section,
            'discipline.' . $action->type->value,
            $action->actionId->value(),
            $action->type->label(),
            $action->statusAt($this->clock->now()),
            $action->startsAt,
            $summary,
            '/moderation/discipline',
        );
    }

    private function canView(): bool
    {
        return $this->gate->allows(PermissionKey::fromString('moderation.discipline.view'));
    }
}
