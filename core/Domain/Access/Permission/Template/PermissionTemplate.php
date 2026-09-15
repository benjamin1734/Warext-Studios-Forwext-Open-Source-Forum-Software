<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use InvalidArgumentException;

final readonly class PermissionTemplate
{
    /** @param list<PermissionTemplateRule> $rules */
    public function __construct(
        private PermissionTemplateKey $key,
        private string $name,
        private string $description,
        private bool $system,
        private array $rules,
    ) {
        $name = trim($this->name);
        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('Permission template name must contain 1-100 UTF-8 bytes.');
        }
        if (strlen($this->description) > 255) {
            throw new InvalidArgumentException('Permission template description must not exceed 255 UTF-8 bytes.');
        }
        if ($this->rules === []) {
            throw new InvalidArgumentException('Permission template must contain at least one rule.');
        }

        $seen = [];
        foreach ($this->rules as $rule) {
            $permissionKey = $rule->definition()->key()->value();
            if (isset($seen[$permissionKey])) {
                throw new InvalidArgumentException('Permission template cannot contain duplicate permission keys.');
            }
            $seen[$permissionKey] = true;
        }
    }

    public function key(): PermissionTemplateKey
    {
        return $this->key;
    }

    public function name(): string
    {
        return trim($this->name);
    }

    public function description(): string
    {
        return $this->description;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /** @return list<PermissionTemplateRule> */
    public function rules(): array
    {
        return $this->rules;
    }
}
