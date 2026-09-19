<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AuthorizerSpellcheckPermissionResolver implements SpellcheckPermissionResolver
{
    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function canUse(EntityId $userId): bool
    {
        return $this->authorizer->allows($userId, PermissionKey::fromString('spellcheck.use'));
    }

    public function canManageOwnDictionary(EntityId $userId): bool
    {
        return $this->authorizer->allows(
            $userId,
            PermissionKey::fromString('spellcheck.dictionary.manage_own'),
        );
    }

    public function canManageSiteDictionary(EntityId $userId): bool
    {
        return $this->authorizer->allows(
            $userId,
            PermissionKey::fromString('spellcheck.dictionary.manage_site'),
        );
    }
}
