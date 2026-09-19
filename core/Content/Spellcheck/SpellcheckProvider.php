<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

interface SpellcheckProvider
{
    public function key(): string;

    public function supports(string $language): bool;

    /** @param list<string> $ignoredWords */
    public function check(SpellcheckRequest $request, array $ignoredWords = []): SpellcheckResult;
}
