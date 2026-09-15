<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class MentionTarget
{
    public function __construct(
        public string $label,
        public string $url,
    ) {
    }
}
