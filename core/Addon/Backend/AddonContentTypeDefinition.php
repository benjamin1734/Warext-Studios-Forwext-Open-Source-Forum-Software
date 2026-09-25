<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Domain\Entity\Entity;
use InvalidArgumentException;

final readonly class AddonContentTypeDefinition
{
    /** @param class-string<Entity> $entityClass */
    public function __construct(
        public string $key,
        public string $entityClass,
        public string $label,
        public bool $searchable = false,
        public bool $reportable = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Add-on content type key is invalid.');
        }
        if (!is_a($this->entityClass, Entity::class, true)) {
            throw new InvalidArgumentException('Add-on content type entity class must implement Entity.');
        }
        if ($this->label === '' || strlen($this->label) > 120 || preg_match('//u', $this->label) !== 1) {
            throw new InvalidArgumentException('Add-on content type label is invalid.');
        }
    }
}
