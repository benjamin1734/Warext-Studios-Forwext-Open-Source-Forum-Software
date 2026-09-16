<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

interface MentionSuggestionProvider
{
    /** @return list<MentionSuggestion> */
    public function suggest(string $query, int $limit = 8): array;
}
