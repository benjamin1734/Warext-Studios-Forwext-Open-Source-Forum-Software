<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Transport;

use InvalidArgumentException;

final readonly class AiModerationHttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
        if ($this->status < 100 || $this->status > 599) {
            throw new InvalidArgumentException('AI provider HTTP status is invalid.');
        }
    }
}
