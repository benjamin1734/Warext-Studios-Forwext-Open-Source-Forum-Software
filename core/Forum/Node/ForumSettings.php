<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use InvalidArgumentException;

final readonly class ForumSettings
{
    public function __construct(
        private bool $allowNewThreads = true,
        private bool $allowReplies = true,
        private bool $requireThreadApproval = false,
        private bool $requirePostApproval = false,
        private ForumDefaultThreadSort $defaultThreadSort = ForumDefaultThreadSort::LastPost,
        private int $threadsPerPage = 20,
    ) {
        if ($this->threadsPerPage < 5 || $this->threadsPerPage > 100) {
            throw new InvalidArgumentException('Forum threads per page must be between 5 and 100.');
        }
    }

    public function allowNewThreads(): bool
    {
        return $this->allowNewThreads;
    }

    public function allowReplies(): bool
    {
        return $this->allowReplies;
    }

    public function requireThreadApproval(): bool
    {
        return $this->requireThreadApproval;
    }

    public function requirePostApproval(): bool
    {
        return $this->requirePostApproval;
    }

    public function defaultThreadSort(): ForumDefaultThreadSort
    {
        return $this->defaultThreadSort;
    }

    public function threadsPerPage(): int
    {
        return $this->threadsPerPage;
    }
}
