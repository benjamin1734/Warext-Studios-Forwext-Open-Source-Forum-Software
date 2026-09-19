<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use Forwext\Core\Domain\Entity\EntityId;

interface SpellcheckDictionaryRepository
{
    /** @return list<string> */
    public function siteWords(string $language): array;

    /** @return list<string> */
    public function userWords(EntityId $userId, string $language): array;

    public function addSite(string $language, string $word, ?EntityId $actorUserId = null): void;

    public function removeSite(string $language, string $word): bool;

    public function addUser(EntityId $userId, string $language, string $word): void;

    public function removeUser(EntityId $userId, string $language, string $word): bool;
}
