<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use InvalidArgumentException;

final readonly class FirstPartyModuleSettingDefinition
{
    /** @var list<FirstPartyModuleScope> */
    public array $scopes;

    /** @var list<string> */
    public array $allowedStrings;

    /**
     * @param list<FirstPartyModuleScope> $scopes
     * @param list<string> $allowedStrings
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public FirstPartyModuleSettingType $type,
        public bool|int|string $defaultValue,
        array $scopes,
        public ?int $minimum = null,
        public ?int $maximum = null,
        array $allowedStrings = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('First-party module setting key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 100
            || $this->description === '' || strlen($this->description) > 500
        ) {
            throw new InvalidArgumentException('First-party module setting text is invalid.');
        }
        if ($scopes === [] || count($scopes) > count(FirstPartyModuleScope::cases())) {
            throw new InvalidArgumentException('First-party module setting scope list is invalid.');
        }
        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (!$scope instanceof FirstPartyModuleScope) {
                throw new InvalidArgumentException('First-party module setting scope is invalid.');
            }
            $normalizedScopes[$scope->value] = $scope;
        }
        $this->scopes = array_values($normalizedScopes);

        $allowed = [];
        foreach ($allowedStrings as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 100) {
                throw new InvalidArgumentException('First-party module setting allowed value is invalid.');
            }
            $allowed[$value] = $value;
        }
        $this->allowedStrings = array_values($allowed);

        if ($this->type === FirstPartyModuleSettingType::Flag && !is_bool($this->defaultValue)) {
            throw new InvalidArgumentException('Flag setting default must be boolean.');
        }
        if ($this->type === FirstPartyModuleSettingType::Integer) {
            if (!is_int($this->defaultValue)) {
                throw new InvalidArgumentException('Integer setting default must be integer.');
            }
            if ($this->minimum !== null && $this->maximum !== null && $this->minimum > $this->maximum) {
                throw new InvalidArgumentException('Integer setting bounds are invalid.');
            }
            $this->normalize($this->defaultValue);
        }
        if ($this->type === FirstPartyModuleSettingType::String) {
            if (!is_string($this->defaultValue)) {
                throw new InvalidArgumentException('String setting default must be string.');
            }
            $this->normalize($this->defaultValue);
        }
    }

    public function supports(FirstPartyModuleScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function normalize(mixed $value): bool|int|string
    {
        return match ($this->type) {
            FirstPartyModuleSettingType::Flag => $this->normalizeFlag($value),
            FirstPartyModuleSettingType::Integer => $this->normalizeInteger($value),
            FirstPartyModuleSettingType::String => $this->normalizeString($value),
        };
    }

    private function normalizeFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new InvalidArgumentException('Module flag setting value is invalid.');
    }

    private function normalizeInteger(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed)
            || ($this->minimum !== null && $parsed < $this->minimum)
            || ($this->maximum !== null && $parsed > $this->maximum)
        ) {
            throw new InvalidArgumentException('Module integer setting value is invalid.');
        }

        return $parsed;
    }

    private function normalizeString(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 500 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Module string setting value is invalid.');
        }
        $value = trim($value);
        if ($this->allowedStrings !== [] && !in_array($value, $this->allowedStrings, true)) {
            throw new InvalidArgumentException('Module string setting value is not allowed.');
        }

        return $value;
    }
}
