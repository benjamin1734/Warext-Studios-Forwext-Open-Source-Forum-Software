<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Domain\Entity\Entity;
use InvalidArgumentException;

final readonly class AddonEntityDefinition
{
    /** @param class-string<Entity> $entityClass */
    public function __construct(
        public string $key,
        public string $entityClass,
        public string $label,
    ) {
        self::assertKey($this->key, 'entity');
        if (!is_a($this->entityClass, Entity::class, true)) {
            throw new InvalidArgumentException('Add-on entity class must implement Entity.');
        }
        if ($this->label === '' || strlen($this->label) > 120 || preg_match('//u', $this->label) !== 1) {
            throw new InvalidArgumentException('Add-on entity label is invalid.');
        }
    }

    private static function assertKey(string $key, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Add-on ' . $label . ' key is invalid.');
        }
    }
}
