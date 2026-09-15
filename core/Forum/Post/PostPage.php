<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

final readonly class PostPage
{
    /** @param list<Post> $posts */
    public function __construct(
        public array $posts,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
