<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Stats;

final readonly class ForumStats
{
    public function __construct(
        public int $forums,
        public int $threads,
        public int $posts,
        public int $activeMembers,
    ) {
    }
}
