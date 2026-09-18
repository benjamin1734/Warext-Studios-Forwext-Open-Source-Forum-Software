<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use InvalidArgumentException;

final readonly class SupportCannedResponse
{
    public function __construct(
        public string $key,
        public string $title,
        public string $body,
        public bool $active,
        public int $sortOrder,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Support canned response key is invalid.');
        }
        if (trim($this->title) === '' || strlen($this->title) > 120) {
            throw new InvalidArgumentException('Support canned response title must contain 1-120 UTF-8 bytes.');
        }
        if (trim($this->body) === '' || strlen($this->body) > 10000) {
            throw new InvalidArgumentException('Support canned response body must contain 1-10000 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Support canned response sort order is invalid.');
        }
    }
}
