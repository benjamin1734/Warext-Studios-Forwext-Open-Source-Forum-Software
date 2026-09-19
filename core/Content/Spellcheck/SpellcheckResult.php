<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final readonly class SpellcheckResult
{
    /** @param list<SpellcheckIssue> $issues */
    public function __construct(
        public string $providerKey,
        public string $language,
        public array $issues,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1) {
            throw new InvalidArgumentException('Spellcheck provider key is invalid.');
        }
        SpellcheckLanguage::normalize($this->language);
        foreach ($this->issues as $issue) {
            if (!$issue instanceof SpellcheckIssue) {
                throw new InvalidArgumentException('Spellcheck result issues are invalid.');
            }
        }
    }

    /** @return array{provider:string,language:string,issue_count:int,issues:list<array{word:string,start:int,length:int,suggestions:list<string>}>} */
    public function toArray(): array
    {
        return [
            'provider'=>$this->providerKey,
            'language'=>$this->language,
            'issue_count'=>count($this->issues),
            'issues'=>array_map(
                static fn (SpellcheckIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}
