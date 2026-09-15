<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use InvalidArgumentException;

final readonly class EditorLimits
{
    public function __construct(
        public int $minCharacters = 1,
        public int $maxCharacters = 100000,
        public int $maxBytes = 100000,
        public int $minWords = 0,
        public ?int $maxWords = null,
    ) {
        if ($this->minCharacters < 0 || $this->maxCharacters < $this->minCharacters || $this->maxCharacters > 1000000) {
            throw new InvalidArgumentException('Editor character limits are invalid.');
        }
        if ($this->maxBytes < 1 || $this->maxBytes > 4000000) {
            throw new InvalidArgumentException('Editor byte limit is invalid.');
        }
        if ($this->minWords < 0 || ($this->maxWords !== null && $this->maxWords < $this->minWords)) {
            throw new InvalidArgumentException('Editor word limits are invalid.');
        }
    }

    /** @return list<string> */
    public function violations(EditorTextMetrics $metrics): array
    {
        $violations = [];
        if ($metrics->characters < $this->minCharacters) {
            $violations[] = 'characters.minimum';
        }
        if ($metrics->characters > $this->maxCharacters) {
            $violations[] = 'characters.maximum';
        }
        if ($metrics->bytes > $this->maxBytes) {
            $violations[] = 'bytes.maximum';
        }
        if ($metrics->words < $this->minWords) {
            $violations[] = 'words.minimum';
        }
        if ($this->maxWords !== null && $metrics->words > $this->maxWords) {
            $violations[] = 'words.maximum';
        }
        return $violations;
    }

    public function accepts(EditorTextMetrics $metrics): bool
    {
        return $this->violations($metrics) === [];
    }
}
