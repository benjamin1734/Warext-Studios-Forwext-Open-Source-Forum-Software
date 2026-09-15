<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class EmbedTarget
{
    public function __construct(
        public string $url,
        public string $label,
    ) {
    }
}
