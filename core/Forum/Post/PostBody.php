<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use InvalidArgumentException;

final readonly class PostBody
{
    private function __construct(private string $source)
    {
    }

    public static function fromString(string $source): self
    {
        $source = trim($source);
        if ($source === '' || strlen($source) > 100000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1
        ) {
            throw new InvalidArgumentException('Post body must contain 1-100000 UTF-8 bytes and no unsafe control characters.');
        }

        return new self($source);
    }

    public function source(): string
    {
        return $this->source;
    }
}
