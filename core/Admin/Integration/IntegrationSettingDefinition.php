<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use InvalidArgumentException;

final readonly class IntegrationSettingDefinition
{
    /**
     * @param list<string> $allowedValues
     */
    public function __construct(
        public string $key,
        public IntegrationSection $section,
        public string $configPath,
        public string $label,
        public string $description,
        public IntegrationSettingType $type,
        public bool $nullable = false,
        public array $allowedValues = [],
        public ?int $minimum = null,
        public ?int $maximum = null,
        public int $maxLength = 500,
        public bool $editable = true,
        public ?string $availabilityNote = null,
    ) {
        if (preg_match('/^integration\.[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Integration setting key is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $this->configPath) !== 1) {
            throw new InvalidArgumentException('Integration configuration path is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 120
            || $this->description === '' || strlen($this->description) > 600
        ) {
            throw new InvalidArgumentException('Integration setting text is invalid.');
        }
        if ($this->maxLength < 1 || $this->maxLength > 20000) {
            throw new InvalidArgumentException('Integration setting maximum length is invalid.');
        }
        if (($this->minimum !== null && $this->maximum !== null) && $this->minimum > $this->maximum) {
            throw new InvalidArgumentException('Integration setting numeric range is invalid.');
        }
        if ($this->type === IntegrationSettingType::Enum && $this->allowedValues === []) {
            throw new InvalidArgumentException('Integration enum setting requires allowed values.');
        }
        foreach ($this->allowedValues as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 120) {
                throw new InvalidArgumentException('Integration enum value is invalid.');
            }
        }
    }

    public function normalize(mixed $raw): bool|int|string|array|null
    {
        if (!$this->editable) {
            throw new InvalidArgumentException('Integration setting is read-only in this runtime.');
        }

        if ($this->nullable && ($raw === null || $raw === '')) {
            return null;
        }

        return match ($this->type) {
            IntegrationSettingType::Flag => $this->flag($raw),
            IntegrationSettingType::Integer => $this->integer($raw),
            IntegrationSettingType::String => $this->string($raw),
            IntegrationSettingType::Enum => $this->enum($raw),
            IntegrationSettingType::StringList => $this->stringList($raw),
            IntegrationSettingType::HttpsUrl => $this->httpsUrl($raw),
            IntegrationSettingType::HttpsUrlList => $this->httpsUrlList($raw),
            IntegrationSettingType::Email => $this->email($raw),
            IntegrationSettingType::SameOriginPath => $this->sameOriginPath($raw),
        };
    }

    private function flag(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) && ($raw === 0 || $raw === 1)) {
            return $raw === 1;
        }
        if (is_string($raw)) {
            return match (strtolower(trim($raw))) {
                '1', 'true', 'on', 'yes' => true,
                '0', 'false', 'off', 'no' => false,
                default => throw new InvalidArgumentException('Integration flag value is invalid.'),
            };
        }

        throw new InvalidArgumentException('Integration flag value is invalid.');
    }

    private function integer(mixed $raw): int
    {
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', trim($raw)) === 1) {
            $value = (int) trim($raw);
        } else {
            throw new InvalidArgumentException('Integration integer value is invalid.');
        }

        if (($this->minimum !== null && $value < $this->minimum)
            || ($this->maximum !== null && $value > $this->maximum)
        ) {
            throw new InvalidArgumentException('Integration integer value is outside the allowed range.');
        }

        return $value;
    }

    private function string(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Integration string value is invalid.');
        }
        $value = trim($raw);
        if ($value === '' && !$this->nullable) {
            throw new InvalidArgumentException('Integration string value cannot be empty.');
        }
        if (strlen($value) > $this->maxLength || preg_match('/[\x00\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Integration string value is invalid.');
        }

        return $value;
    }

    private function enum(mixed $raw): string
    {
        $value = $this->string($raw);
        if (!in_array($value, $this->allowedValues, true)) {
            throw new InvalidArgumentException('Integration enum value is not allowed.');
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $raw): array
    {
        $parts = $this->listParts($raw);
        $result = [];
        foreach ($parts as $part) {
            if (strlen($part) > $this->maxLength || preg_match('/[\x00-\x1F\x7F]/', $part) === 1) {
                throw new InvalidArgumentException('Integration list item is invalid.');
            }
            $result[$part] = $part;
        }

        return array_slice(array_values($result), 0, 50);
    }

    private function httpsUrl(mixed $raw): string
    {
        $value = $this->string($raw);
        self::assertHttpsUrl($value, $this->maxLength);

        return $value;
    }

    /** @return list<string> */
    private function httpsUrlList(mixed $raw): array
    {
        $parts = $this->listParts($raw);
        if (count($parts) > 20) {
            throw new InvalidArgumentException('Integration URL list is too large.');
        }
        $result = [];
        foreach ($parts as $part) {
            self::assertHttpsUrl($part, $this->maxLength);
            $result[$part] = $part;
        }

        return array_values($result);
    }

    private function email(mixed $raw): string
    {
        $value = $this->string($raw);
        if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Integration email value is invalid.');
        }

        return $value;
    }

    private function sameOriginPath(mixed $raw): string
    {
        $value = $this->string($raw);
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')
            || str_contains($value, '?') || str_contains($value, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Integration same-origin path is invalid.');
        }
        foreach (explode('/', trim($value, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Integration same-origin path contains an ambiguous segment.');
            }
        }

        return $value;
    }

    /** @return list<string> */
    private function listParts(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        } else {
            throw new InvalidArgumentException('Integration list value is invalid.');
        }

        $normalized = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                throw new InvalidArgumentException('Integration list item is invalid.');
            }
            $part = trim($part);
            if ($part !== '') {
                $normalized[] = $part;
            }
        }

        return $normalized;
    }

    private static function assertHttpsUrl(string $value, int $maxLength): void
    {
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Integration HTTPS URL is too long.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Integration URL must be an absolute HTTPS URL without credentials or fragment.');
        }
    }
}
