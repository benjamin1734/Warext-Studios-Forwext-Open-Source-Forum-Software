<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

final readonly class PostCounters
{
    public function __construct(
        public int $active,
        public int $visible,
    ) {
    }
}
