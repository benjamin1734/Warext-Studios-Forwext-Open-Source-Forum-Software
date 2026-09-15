<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use InvalidArgumentException;

final readonly class CustomFieldDefinition
{
    /** @param array<string, string> $choices */
    public function __construct(
        private CustomFieldKey $key,
        private CustomFieldTarget $target,
        private string $label,
        private CustomFieldType $type,
        private bool $required = false,
        private ?int $minimum = null,
        private ?int $maximum = null,
        private array $choices = [],
        private int $sortOrder = 0,
        private bool $enabled = true,
    ) {
        $label = trim($this->label);
        if ($label === '' || strlen($label) > 100) {
            throw new InvalidArgumentException('Custom field label must contain 1-100 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Custom field sort order must fit an unsigned 16-bit integer.');
        }
        if ($this->minimum !== null && $this->minimum < 0 && $this->type === CustomFieldType::Text) {
            throw new InvalidArgumentException('Text custom field minimum length cannot be negative.');
        }
        if ($this->maximum !== null && $this->maximum < 0 && $this->type === CustomFieldType::Text) {
            throw new InvalidArgumentException('Text custom field maximum length cannot be negative.');
        }
        if ($this->minimum !== null && $this->maximum !== null && $this->minimum > $this->maximum) {
            throw new InvalidArgumentException('Custom field minimum cannot exceed maximum.');
        }
        if ($this->type === CustomFieldType::Text && ($this->maximum ?? 100000) > 100000) {
            throw new InvalidArgumentException('Text custom field maximum length cannot exceed 100000 bytes.');
        }

        if ($this->type === CustomFieldType::Choice) {
            if ($this->choices === [] || count($this->choices) > 100) {
                throw new InvalidArgumentException('Choice custom fields require 1-100 configured options.');
            }
            foreach ($this->choices as $choiceKey => $choiceLabel) {
                if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $choiceKey) !== 1
                    || trim($choiceLabel) === '' || strlen($choiceLabel) > 100
                ) {
                    throw new InvalidArgumentException('Custom field choice keys or labels are invalid.');
                }
            }
            if ($this->minimum !== null || $this->maximum !== null) {
                throw new InvalidArgumentException('Choice custom fields cannot define numeric/text bounds.');
            }
        } elseif ($this->choices !== []) {
            throw new InvalidArgumentException('Only choice custom fields can define choices.');
        }

        if ($this->type === CustomFieldType::Boolean
            && ($this->minimum !== null || $this->maximum !== null)
        ) {
            throw new InvalidArgumentException('Boolean custom fields cannot define bounds.');
        }
    }

    public function key(): CustomFieldKey
    {
        return $this->key;
    }

    public function target(): CustomFieldTarget
    {
        return $this->target;
    }

    public function label(): string
    {
        return trim($this->label);
    }

    public function type(): CustomFieldType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function minimum(): ?int
    {
        return $this->minimum;
    }

    public function maximum(): ?int
    {
        return $this->maximum;
    }

    /** @return array<string, string> */
    public function choices(): array
    {
        return $this->choices;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function validate(mixed $raw): CustomFieldValue
    {
        $value = match ($this->type) {
            CustomFieldType::Text => $this->validateText($raw),
            CustomFieldType::Integer => $this->validateInteger($raw),
            CustomFieldType::Boolean => $this->validateBoolean($raw),
            CustomFieldType::Choice => $this->validateChoice($raw),
        };

        return new CustomFieldValue($this->type, $value);
    }

    private function validateText(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Text custom field value must be a string.');
        }
        $length = strlen($raw);
        if ($length > 100000) {
            throw new InvalidArgumentException('Text custom field value exceeds the platform hard limit.');
        }
        if ($this->minimum !== null && $length < $this->minimum) {
            throw new InvalidArgumentException('Text custom field value is shorter than the configured minimum.');
        }
        if ($this->maximum !== null && $length > $this->maximum) {
            throw new InvalidArgumentException('Text custom field value exceeds the configured maximum.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            throw new InvalidArgumentException('Text custom field value contains unsafe control characters.');
        }
        return $raw;
    }

    private function validateInteger(mixed $raw): int
    {
        if (!is_int($raw)) {
            throw new InvalidArgumentException('Integer custom field value must be an integer.');
        }
        if ($this->minimum !== null && $raw < $this->minimum) {
            throw new InvalidArgumentException('Integer custom field value is below the configured minimum.');
        }
        if ($this->maximum !== null && $raw > $this->maximum) {
            throw new InvalidArgumentException('Integer custom field value exceeds the configured maximum.');
        }
        return $raw;
    }

    private function validateBoolean(mixed $raw): bool
    {
        if (!is_bool($raw)) {
            throw new InvalidArgumentException('Boolean custom field value must be boolean.');
        }
        return $raw;
    }

    private function validateChoice(mixed $raw): string
    {
        if (!is_string($raw) || !array_key_exists($raw, $this->choices)) {
            throw new InvalidArgumentException('Choice custom field value is not an allowed option.');
        }
        return $raw;
    }
}
