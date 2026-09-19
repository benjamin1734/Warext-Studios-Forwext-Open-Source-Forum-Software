<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

use InvalidArgumentException;

final readonly class SpellcheckIssue
{
    /** @param list<string> $suggestions */
    public function __construct(
        public string $word,
        public int $start,
        public int $length,
        public array $suggestions,
    ) {
        if ($this->word === '' || preg_match('//u', $this->word) !== 1
            || $this->start < 0 || $this->length < 1
        ) {
            throw new InvalidArgumentException('Spellcheck issue location is invalid.');
        }
        if (count($this->suggestions) > 8) {
            throw new InvalidArgumentException('Spellcheck issue has too many suggestions.');
        }
        foreach ($this->suggestions as $suggestion) {
            if (!is_string($suggestion) || trim($suggestion) === '' || strlen($suggestion) > 128
                || preg_match('//u', $suggestion) !== 1
            ) {
                throw new InvalidArgumentException('Spellcheck suggestion is invalid.');
            }
        }
    }

    /** @return array{word:string,start:int,length:int,suggestions:list<string>} */
    public function toArray(): array
    {
        return [
            'word'=>$this->word,
            'start'=>$this->start,
            'length'=>$this->length,
            'suggestions'=>$this->suggestions,
        ];
    }
}
