<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use InvalidArgumentException;

final readonly class AddonSettingDefinition
{
    /** @var list<string> */
    public array $allowedStrings;

    /** @param list<string> $allowedStrings */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public AddonSettingType $type,
        public bool|int|string $defaultValue,
        public ?int $minimum = null,
        public ?int $maximum = null,
        array $allowedStrings = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Add-on setting key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 120
            || $this->description === '' || strlen($this->description) > 500
            || preg_match('//u', $this->label . $this->description) !== 1
        ) {
            throw new InvalidArgumentException('Add-on setting text is invalid.');
        }

        $allowed = [];
        foreach ($allowedStrings as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 191 || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Add-on setting allowed value is invalid.');
            }
            $allowed[$value] = $value;
        }
        $this->allowedStrings = array_values($allowed);

        if ($this->minimum !== null && $this->maximum !== null && $this->minimum > $this->maximum) {
            throw new InvalidArgumentException('Add-on setting integer bounds are invalid.');
        }
        if ($this->normalize($this->defaultValue) !== $this->defaultValue) {
            throw new InvalidArgumentException('Add-on setting default value must already use canonical type and formatting.');
        }
    }

    public function normalize(mixed $value): bool|int|string
    {
        return match ($this->type) {
            AddonSettingType::Flag => $this->normalizeFlag($value),
            AddonSettingType::Integer => $this->normalizeInteger($value),
            AddonSettingType::String => $this->normalizeString($value),
        };
    }

    private function normalizeFlag(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (in_array($value, [1, '1', 'true'], true)) return true;
        if (in_array($value, [0, '0', 'false'], true)) return false;
        throw new InvalidArgumentException('Add-on flag setting value is invalid.');
    }

    private function normalizeInteger(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed)
            || ($this->minimum !== null && $parsed < $this->minimum)
            || ($this->maximum !== null && $parsed > $this->maximum)
        ) {
            throw new InvalidArgumentException('Add-on integer setting value is invalid.');
        }
        return $parsed;
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2000 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Add-on string setting value is invalid.');
        }
        $value = trim($value);
        if ($this->allowedStrings !== [] && !in_array($value, $this->allowedStrings, true)) {
            throw new InvalidArgumentException('Add-on string setting value is not allowed.');
        }
        return $value;
    }
}
