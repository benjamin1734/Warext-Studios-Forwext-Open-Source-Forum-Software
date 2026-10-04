<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\App\Web\Access\RoleAppearanceHtml;
use Forwext\Core\Domain\Access\Appearance\DatabaseRolePresentationReader;
use Forwext\Core\Domain\Access\Appearance\RoleDisplayContext;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ThreadAuthorRolePresenter
{
    public function __construct(
        private DatabaseUserAccessAssignmentProvider $assignments,
        private DatabaseRolePresentationReader $roles,
        private RoleAppearanceHtml $renderer,
    ) {
    }

    /**
     * @param list<EntityId> $userIds
     * @return array<string,string>
     */
    public function renderForUsers(array $userIds): array
    {
        $assignments = $this->assignments->findMany($userIds);
        if ($assignments === []) {
            return [];
        }

        $roleIds = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment->roleIds() as $roleId) {
                $roleIds[$roleId->value()] = $roleId;
            }
        }
        $presentations = $this->roles->findMany(array_values($roleIds));
        if ($presentations === []) {
            return [];
        }

        $rendered = [];
        foreach ($assignments as $userId => $assignment) {
            $candidates = [];
            foreach ($assignment->roleIds() as $roleId) {
                $presentation = $presentations[$roleId->value()] ?? null;
                if ($presentation === null || !$presentation->appearance->showPosts()) {
                    continue;
                }
                $candidates[] = $presentation;
            }
            usort(
                $candidates,
                static fn ($left, $right): int =>
                    [$right->role->priority(), $left->role->name(), $left->role->id()->value()]
                    <=> [$left->role->priority(), $right->role->name(), $right->role->id()->value()],
            );

            foreach ($candidates as $presentation) {
                $html = $this->renderer->render(
                    $presentation->role,
                    $presentation->appearance,
                    RoleDisplayContext::Post,
                );
                if ($html !== '') {
                    $rendered[$userId] = $html;
                    break;
                }
            }
        }

        return $rendered;
    }
}
