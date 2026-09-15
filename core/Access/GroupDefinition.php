<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

final readonly class GroupDefinition
{
    public function __construct(
        public GroupKey $key,
        public string $name,
        public string $description = '',
        public bool $system = false,
        public bool $active = true,
        public int $sortOrder = 100,
    ) {
        self::assertLabel($name, 191, 'Group name');
        self::assertLabel($description, 2000, 'Group description', true);
        if ($sortOrder < -1_000_000 || $sortOrder > 1_000_000) {
            throw new AccessException('Group sort order is out of range.');
        }
    }

    private static function assertLabel(string $value, int $maximumBytes, string $label, bool $allowEmpty = false): void
    {
        if ((!$allowEmpty && trim($value) === '') || strlen($value) > $maximumBytes) {
            throw new AccessException($label . ' is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new AccessException($label . ' contains unsupported control characters.');
        }
    }
}
