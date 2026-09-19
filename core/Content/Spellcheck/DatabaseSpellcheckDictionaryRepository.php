<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseSpellcheckDictionaryRepository implements SpellcheckDictionaryRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function siteWords(string $language): array
    {
        $language = SpellcheckLanguage::normalize($language);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT word FROM forwext_spellcheck_site_dictionary WHERE language=:language ORDER BY normalized_word',
            ['language'=>$language],
        ));
        return array_values(array_map(static fn (array $row): string => (string) $row['word'], $rows));
    }

    public function userWords(EntityId $userId, string $language): array
    {
        $language = SpellcheckLanguage::normalize($language);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT word FROM forwext_spellcheck_user_dictionary '
            . 'WHERE user_id=:user_id AND language=:language ORDER BY normalized_word',
            ['user_id'=>$userId->value(),'language'=>$language],
        ));
        return array_values(array_map(static fn (array $row): string => (string) $row['word'], $rows));
    }

    public function addSite(string $language, string $word, ?EntityId $actorUserId = null): void
    {
        $language = SpellcheckLanguage::normalize($language);
        $normalized = SpellcheckWord::normalize($word);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_spellcheck_site_dictionary '
            . '(language,normalized_word,word,actor_user_id,created_at_utc) '
            . 'VALUES (:language,:normalized_word,:word,:actor_user_id,:created_at_utc) '
            . 'ON DUPLICATE KEY UPDATE word=VALUES(word),actor_user_id=VALUES(actor_user_id)',
            [
                'language'=>$language,
                'normalized_word'=>$normalized,
                'word'=>trim($word),
                'actor_user_id'=>$actorUserId?->value(),
                'created_at_utc'=>$this->now(),
            ],
        ));
    }

    public function removeSite(string $language, string $word): bool
    {
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_spellcheck_site_dictionary WHERE language=:language AND normalized_word=:word',
            ['language'=>SpellcheckLanguage::normalize($language),'word'=>SpellcheckWord::normalize($word)],
        )) > 0;
    }

    public function addUser(EntityId $userId, string $language, string $word): void
    {
        $language = SpellcheckLanguage::normalize($language);
        $normalized = SpellcheckWord::normalize($word);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_spellcheck_user_dictionary '
            . '(user_id,language,normalized_word,word,created_at_utc) '
            . 'VALUES (:user_id,:language,:normalized_word,:word,:created_at_utc) '
            . 'ON DUPLICATE KEY UPDATE word=VALUES(word)',
            [
                'user_id'=>$userId->value(),
                'language'=>$language,
                'normalized_word'=>$normalized,
                'word'=>trim($word),
                'created_at_utc'=>$this->now(),
            ],
        ));
    }

    public function removeUser(EntityId $userId, string $language, string $word): bool
    {
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_spellcheck_user_dictionary '
            . 'WHERE user_id=:user_id AND language=:language AND normalized_word=:word',
            [
                'user_id'=>$userId->value(),
                'language'=>SpellcheckLanguage::normalize($language),
                'word'=>SpellcheckWord::normalize($word),
            ],
        )) > 0;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
