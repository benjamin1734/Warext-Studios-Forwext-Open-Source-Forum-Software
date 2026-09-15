<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PollOption
{
    public function __construct(
        private EntityId $id,
        private string $text,
        private int $sortOrder,
    ) {
        PollOptionId::assert($this->id);
        $text = trim($this->text);
        if ($text === '' || strlen($text) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new InvalidArgumentException('Poll option text must contain 1-200 safe UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 255) {
            throw new InvalidArgumentException('Poll option sort order must be between 0 and 255.');
        }
    }

    public function id(): EntityId
    {
        return $this->id;
    }

    public function text(): string
    {
        return trim($this->text);
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
