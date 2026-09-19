<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

final readonly class AiModerationRedactionResult
{
    public function __construct(
        public string $text,
        public bool $redacted,
    ) {
    }
}
