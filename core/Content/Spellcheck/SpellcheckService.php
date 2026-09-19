<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Entity\EntityId;
use LogicException;

final readonly class SpellcheckService
{
    public function __construct(
        private SpellcheckProviderRegistry $providers,
        private SpellcheckDictionaryRepository $dictionary,
        private SpellcheckPermissionResolver $permissions,
        private ?AuditRecorder $audit = null,
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

    public function addUserWord(
        EntityId $userId,
        string $language,
        string $word,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): void {
        if (!$this->permissions->canManageOwnDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Own spellcheck dictionary management is not permitted.');
        }
        $language = SpellcheckLanguage::normalize($language);
        $wordHash = hash('sha256', SpellcheckWord::normalize($word));
        $event = $this->dictionaryAuditEvent($userId, 'user', 'add', $language, $wordHash, $requestId, $at);
        $this->auditRecorder()->mutate($event, function () use ($userId, $language, $word): void {
            $this->dictionary->addUser($userId, $language, $word);
        });
    }

    public function removeUserWord(
        EntityId $userId,
        string $language,
        string $word,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): bool {
        if (!$this->permissions->canManageOwnDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Own spellcheck dictionary management is not permitted.');
        }
        $language = SpellcheckLanguage::normalize($language);
        $wordHash = hash('sha256', SpellcheckWord::normalize($word));
        $event = $this->dictionaryAuditEvent($userId, 'user', 'remove', $language, $wordHash, $requestId, $at);
        return $this->auditRecorder()->mutate(
            $event,
            fn (): bool => $this->dictionary->removeUser($userId, $language, $word),
        );
    }

    public function addSiteWord(
        EntityId $userId,
        string $language,
        string $word,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): void {
        if (!$this->permissions->canManageSiteDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Site spellcheck dictionary management is not permitted.');
        }
        $language = SpellcheckLanguage::normalize($language);
        $wordHash = hash('sha256', SpellcheckWord::normalize($word));
        $event = $this->dictionaryAuditEvent($userId, 'site', 'add', $language, $wordHash, $requestId, $at);
        $this->auditRecorder()->mutate($event, function () use ($userId, $language, $word): void {
            $this->dictionary->addSite($language, $word, $userId);
        });
    }

    public function removeSiteWord(
        EntityId $userId,
        string $language,
        string $word,
        ?AuditRequestId $requestId = null,
        ?DateTimeImmutable $at = null,
    ): bool {
        if (!$this->permissions->canManageSiteDictionary($userId)) {
            throw new SpellcheckAccessDeniedException('Site spellcheck dictionary management is not permitted.');
        }
        $language = SpellcheckLanguage::normalize($language);
        $wordHash = hash('sha256', SpellcheckWord::normalize($word));
        $event = $this->dictionaryAuditEvent($userId, 'site', 'remove', $language, $wordHash, $requestId, $at);
        return $this->auditRecorder()->mutate(
            $event,
            fn (): bool => $this->dictionary->removeSite($language, $word),
        );
    }

    private function dictionaryAuditEvent(
        EntityId $actorUserId,
        string $scope,
        string $operation,
        string $language,
        string $wordHash,
        ?AuditRequestId $requestId,
        ?DateTimeImmutable $at,
    ): AuditEvent {
        $at = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actorUserId,
            AuditAction::fromString('content.spellcheck.dictionary.' . $scope . '.' . $operation),
            'spellcheck.dictionary',
            $wordHash,
            null,
            null,
            $requestId ?? AuditRequestId::generate(),
            [],
            [
                'scope'=>$scope,
                'language'=>$language,
                'operation'=>$operation,
                'word_fingerprint'=>$wordHash,
            ],
            $at,
        );
    }

    private function auditRecorder(): AuditRecorder
    {
        return $this->audit ?? throw new LogicException('Spellcheck dictionary mutations require central audit.');
    }
}
