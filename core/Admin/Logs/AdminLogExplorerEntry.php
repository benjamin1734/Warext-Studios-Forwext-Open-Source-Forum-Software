<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Logs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdminLogExplorerEntry
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public string $source,
        public DateTimeImmutable $occurredAt,
        public string $title,
        public string $detail,
        public ?string $actor,
        public ?string $target,
        public array $context = [],
    ) {
        if (!in_array($this->source, ['system', 'audit', 'user'], true)) {
            throw new InvalidArgumentException('Admin log source is invalid.');
        }
    }
}
