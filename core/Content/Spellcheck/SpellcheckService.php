<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class SpellcheckService
{
    public function __construct(
        private SpellcheckProviderRegistry $providers,
        private SpellcheckDictionaryRepository $dictionary,
        private SpellcheckPermissionResolver $permissions,
    ) {
    }

    public function canUse(EntityId $userId): bool
    {
        return $this->permissions->canUse($userId);
    }

    public function canManageOwnDictionary(EntityId $userId): bool
    {
        return $this->permissions->canManageOwnDictionary($userId);
    }

    public function canManageSiteDictionary(EntityId $userId): bool
    {
        return $this->permissions->canManageSiteDictionary($userId);
    }

    public function check(EntityId $userId, string $text, string $language = 'tr-tr'): SpellcheckResult
    {
        if (!$this->permissions->canUse($userId)) {
            throw new SpellcheckAccessDeniedException('Spellcheck use is not permitted.');
        }
        $request = new SpellcheckRequest($text, $language);
        $ignored = array_values(array_unique(array_merge(
            $this->dictionary->siteWords($request->language),
            $this->dictionary->userWords($userId, $request->language),
        )));
        return $this->providers->resolve($request->language)->check($request, $ignored);
    }

    public function checkIfAllowed(EntityId $userId, string $text, string $language = 'tr-tr'): ?SpellcheckResult
    {
        if (!$this->permissions->canUse($userId)) {
            return null;
        }
        return $this->check($userId, $text, $language);
    }

    /** @return array{user:list<string>,site:list<string>} */
    public function dictionary(EntityId $userId, string $language = 'tr-tr'): array
    {
        if (!$this->permissions->canUse($userId)) {
            throw new SpellcheckAccessDeniedException('Spellcheck use is not permitted.');
        }
        $language = SpellcheckLanguage::normalize($language);
        return [
            'user'=>$this->dictionary->userWords($userId, $language),
            'site'=>$this->dictionary->siteWords($language),
        ];
    }

    public function addUserWord(EntityId $userId, string $language, string $word): void
    {
        if (!$this->permissions->canManageOwnDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Own spellcheck dictionary management is not permitted.');
        }
        $this->dictionary->addUser($userId, $language, $word);
    }

    public function removeUserWord(EntityId $userId, string $language, string $word): bool
    {
        if (!$this->permissions->canManageOwnDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Own spellcheck dictionary management is not permitted.');
        }
        return $this->dictionary->removeUser($userId, $language, $word);
    }

    public function addSiteWord(EntityId $userId, string $language, string $word): void
    {
        if (!$this->permissions->canManageSiteDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Site spellcheck dictionary management is not permitted.');
        }
        $this->dictionary->addSite($language, $word, $userId);
    }

    public function removeSiteWord(EntityId $userId, string $language, string $word): bool
    {
        if (!$this->permissions->canManageSiteDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Site spellcheck dictionary management is not permitted.');
        }
        return $this->dictionary->removeSite($language, $word);
    }
}
