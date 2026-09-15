<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use Forwext\Core\Forum\Thread\Thread;

final readonly class PublishedThread
{
    public function __construct(
        public Thread $thread,
        public Post $firstPost,
    ) {
    }
}
