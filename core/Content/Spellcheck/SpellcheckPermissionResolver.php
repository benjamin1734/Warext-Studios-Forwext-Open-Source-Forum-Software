<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use Forwext\Core\Domain\Entity\EntityId;

interface SpellcheckPermissionResolver
{
    public function canUse(EntityId $userId): bool;

    public function canManageOwnDictionary(EntityId $userId): bool;

    public function canManageSiteDictionary(EntityId $userId): bool;
}
