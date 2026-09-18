<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Support\Ticket\SupportCategory;
use InvalidArgumentException;

final readonly class SupportFieldDefinition
{
    /**
     * @param array<string,string> $choices
     */
    public function __construct(
        public string $categoryKey,
        public string $fieldKey,
        public string $label,
        public SupportFieldType $type,
        public bool $required,
        public array $choices,
        public int $maxLength,
        public string $helpText,
        public int $sortOrder,
        public bool $active,
    ) {
        SupportCategory::assertKey($this->categoryKey);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->fieldKey) !== 1) {
            throw new InvalidArgumentException('Support field key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('Support field label must contain 1-120 UTF-8 bytes.');
        }
        if (strlen($this->helpText) > 500) {
            throw new InvalidArgumentException('Support field help text cannot exceed 500 UTF-8 bytes.');
        }
        if ($this->maxLength < 1 || $this->maxLength > 10000) {
            throw new InvalidArgumentException('Support field maximum length must be 1-10000 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Support field sort order is invalid.');
        }

        foreach ($this->choices as $key => $label) {
            if (!is_string($key) || !is_string($label)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $key) !== 1
                || trim($label) === '' || strlen($label) > 120
            ) {
                throw new InvalidArgumentException('Support field choice is invalid.');
            }
        }
        if ($this->type === SupportFieldType::Select && $this->choices === []) {
            throw new InvalidArgumentException('Support select fields require choices.');
        }
        if ($this->type !== SupportFieldType::Select && $this->choices !== []) {
            throw new InvalidArgumentException('Only support select fields may define choices.');
        }
    }

    public function validate(mixed $raw): SupportFieldValue
    {
        if ($this->type === SupportFieldType::Checkbox) {
            if ($raw !== null && !is_bool($raw) && !is_int($raw) && !is_string($raw)) {
                throw new InvalidArgumentException('Support checkbox value is invalid.');
            }
            $checked = in_array($raw, [true, 1, '1', 'true', 'on', 'yes'], true);
            if ($this->required && !$checked) {
                throw new InvalidArgumentException('Required support checkbox is not accepted.');
            }
            return new SupportFieldValue($this->type, $checked);
        }

        if ($raw === null) {
            if ($this->required) {
                throw new InvalidArgumentException('Required support field is missing.');
            }
            $raw = '';
        } elseif (!is_string($raw)) {
            throw new InvalidArgumentException('Support field value has an invalid type.');
        }

        $value = trim($raw);
        if ($this->required && $value === '') {
            throw new InvalidArgumentException('Required support field cannot be empty.');
        }
        if (strlen($value) > $this->maxLength) {
            throw new InvalidArgumentException('Support field exceeds its maximum length.');
        }
        if ($this->type === SupportFieldType::Select && $value !== '' && !array_key_exists($value, $this->choices)) {
            throw new InvalidArgumentException('Support field choice is unavailable.');
        }

        return new SupportFieldValue($this->type, $value);
    }
}
