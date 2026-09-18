<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationRequest
{
    public function __construct(
        public string $contentType,
        public string $text,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->contentType) !== 1) {
            throw new InvalidArgumentException('AI moderation content type is invalid.');
        }
        if ($this->text === '' || strlen($this->text) > 1_000_000 || preg_match('//u', $this->text) !== 1) {
            throw new InvalidArgumentException('AI moderation text is invalid.');
        }
    }
}
