<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ThemeDefinition
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $themeId,
        public string $key,
        public string $name,
        public ?EntityId $parentThemeId,
        public ?EntityId $stagingRevisionId,
        public ?EntityId $publishedRevisionId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->themeId->value()) !== 1) {
            throw new InvalidArgumentException('Theme id must be a 128-bit lowercase hexadecimal identifier.');
        }
        if (
            strlen($this->key) < 2
            || strlen($this->key) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $this->key) !== 1
        ) {
            throw new InvalidArgumentException('Theme key is invalid.');
        }
        if (
            trim($this->name) === ''
            || strlen($this->name) > 120
            || preg_match('//u', $this->name) !== 1
        ) {
            throw new InvalidArgumentException('Theme name is invalid.');
        }
        if ($this->parentThemeId !== null && $this->parentThemeId->equals($this->themeId)) {
            throw new InvalidArgumentException('Theme cannot inherit from itself.');
        }

        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
