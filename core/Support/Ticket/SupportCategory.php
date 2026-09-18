<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use InvalidArgumentException;

final readonly class SupportCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public SupportTicketPriority $defaultPriority,
        public ?int $firstResponseMinutes,
        public ?int $resolutionMinutes,
        public int $sortOrder,
        public bool $active,
    ) {
        self::assertKey($this->key);
        if (trim($this->label) === '' || strlen($this->label) > 100) {
            throw new InvalidArgumentException('Support category label must contain 1-100 UTF-8 bytes.');
        }
        if (strlen($this->description) > 255) {
            throw new InvalidArgumentException('Support category description cannot exceed 255 UTF-8 bytes.');
        }
        self::assertSlaMinutes($this->firstResponseMinutes, 'first response');
        self::assertSlaMinutes($this->resolutionMinutes, 'resolution');
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Support category sort order is invalid.');
        }
    }

    public static function assertKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Support category key is invalid.');
        }
    }

    private static function assertSlaMinutes(?int $value, string $label): void
    {
        if ($value !== null && ($value < 1 || $value > 525600)) {
            throw new InvalidArgumentException('Support category ' . $label . ' SLA must be 1-525600 minutes.');
        }
    }
}
