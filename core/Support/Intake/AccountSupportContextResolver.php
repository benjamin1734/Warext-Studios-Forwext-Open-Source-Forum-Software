<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;

final readonly class AccountSupportContextResolver implements SupportContextResolver
{
    public function __construct(
        private UserRepository $users,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function type(): SupportContextType
    {
        return SupportContextType::Account;
    }

    public function resolve(EntityId $actorUserId, EntityId $targetId): SupportContextLink
    {
        if (!$actorUserId->equals($targetId)
            && !$this->authorizer->allows(
                $actorUserId,
                PermissionKey::fromString('support.ticket.view_all'),
            )
        ) {
            throw new SupportContextUnavailableException('Linked account is unavailable.');
        }

        $user = $this->users->find($targetId)
            ?? throw new SupportContextUnavailableException('Linked account is unavailable.');

        return new SupportContextLink(
            SupportContextType::Account,
            $user->id(),
            $user->username()->display(),
        );
    }
}
