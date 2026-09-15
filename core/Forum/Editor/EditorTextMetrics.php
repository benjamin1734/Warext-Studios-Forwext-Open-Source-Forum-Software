<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use InvalidArgumentException;

final readonly class EditorTextMetrics
{
    private function __construct(
        public int $characters,
        public int $words,
        public int $bytes,
    ) {
    }

    public static function measure(string $source): self
    {
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('Editor source must be valid UTF-8.');
        }

        $characters = preg_match_all('/./us', $source, $characterMatches);
        $words = preg_match_all('/[\p{L}\p{N}]+(?:[\'’_-][\p{L}\p{N}]+)*/u', $source, $wordMatches);
        if ($characters === false || $words === false) {
            throw new InvalidArgumentException('Editor source metrics could not be calculated.');
        }

        return new self($characters, $words, strlen($source));
    }
}
