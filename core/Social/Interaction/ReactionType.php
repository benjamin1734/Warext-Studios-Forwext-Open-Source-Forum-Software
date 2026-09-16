<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use InvalidArgumentException;

final readonly class ReactionType
{
    public string $label;

    public function __construct(
        public string $key,
        string $label,
        public int $score,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Reaction type key is invalid.');
        }
        $label = trim($label);
        if ($label === '' || strlen($label) > 64 || preg_match('//u', $label) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label) === 1
        ) {
            throw new InvalidArgumentException('Reaction type label is invalid.');
        }
        if ($this->score < -100 || $this->score > 100) {
            throw new InvalidArgumentException('Reaction score is outside the supported range.');
        }
        $this->label = $label;
    }
}
