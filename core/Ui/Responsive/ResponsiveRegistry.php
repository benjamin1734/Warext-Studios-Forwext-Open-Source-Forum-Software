<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ResponsiveRegistry
{
    /** @var array<string, ResponsiveBreakpoint> */
    private array $breakpoints;

    /** @var list<ResponsiveRule> */
    private array $rules;

    /**
     * @param list<ResponsiveBreakpoint> $breakpoints
     * @param list<ResponsiveRule> $rules
     */
    public function __construct(
        public readonly int $manifestVersion,
        array $breakpoints,
        array $rules,
    ) {
        if ($this->manifestVersion !== 1) {
            throw new InvalidArgumentException('Unsupported responsive manifest version.');
        }

        $indexed = [];
        foreach ($breakpoints as $breakpoint) {
            if (isset($indexed[$breakpoint->key])) {
                throw new InvalidArgumentException('Duplicate responsive breakpoint key.');
            }
            $indexed[$breakpoint->key] = $breakpoint;
        }

        foreach (['mobile', 'tablet', 'desktop'] as $required) {
            if (!isset($indexed[$required])) {
                throw new InvalidArgumentException('Responsive manifest is missing breakpoint: ' . $required);
            }
        }

        $ordered = array_values($indexed);
        usort(
            $ordered,
            static fn (ResponsiveBreakpoint $left, ResponsiveBreakpoint $right): int =>
                $left->minWidth <=> $right->minWidth,
        );
        for ($index = 1, $count = count($ordered); $index < $count; ++$index) {
            $previous = $ordered[$index - 1];
            $current = $ordered[$index];
            if ($previous->maxWidth === null || $current->minWidth <= $previous->maxWidth) {
                throw new InvalidArgumentException('Responsive breakpoints must not overlap.');
            }
        }

        $seenRules = [];
        foreach ($rules as $rule) {
            if (!isset($indexed[$rule->breakpoint])) {
                throw new InvalidArgumentException('Responsive rule references an unknown breakpoint.');
            }
            $key = $rule->breakpoint . ':' . $rule->target->value;
            if (isset($seenRules[$key])) {
                throw new InvalidArgumentException('Duplicate responsive rule target for breakpoint.');
            }
            $seenRules[$key] = true;
        }

        ksort($indexed, SORT_STRING);
        $this->breakpoints = $indexed;
        $this->rules = array_values($rules);
    }

    public static function coreDefaults(): self
    {
        $path = dirname(__DIR__, 3) . '/resources/appearance/forwext-responsive-default.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Core responsive manifest could not be read.');
        }

        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Responsive manifest is not valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($manifest)) {
            throw new InvalidArgumentException('Responsive manifest must be an object.');
        }

        $version = $manifest['version'] ?? null;
        $rawBreakpoints = $manifest['breakpoints'] ?? null;
        $rawRules = $manifest['rules'] ?? null;
        if (
            !is_int($version)
            || !is_array($rawBreakpoints)
            || !array_is_list($rawBreakpoints)
            || !is_array($rawRules)
            || !array_is_list($rawRules)
        ) {
            throw new InvalidArgumentException('Responsive manifest shape is invalid.');
        }

        $breakpoints = [];
        foreach ($rawBreakpoints as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Responsive breakpoint entry is invalid.');
            }
            $key = $entry['key'] ?? null;
            $min = $entry['min_width'] ?? null;
            $max = $entry['max_width'] ?? null;
            if (!is_string($key) || !is_int($min) || ($max !== null && !is_int($max))) {
                throw new InvalidArgumentException('Responsive breakpoint fields are invalid.');
            }
            $breakpoints[] = new ResponsiveBreakpoint($key, $min, $max);
        }

        $rules = [];
        foreach ($rawRules as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Responsive rule entry is invalid.');
            }

            $breakpoint = $entry['breakpoint'] ?? null;
            $rawTarget = $entry['target'] ?? null;
            $rawVisibility = $entry['visibility'] ?? 'inherit';
            $fontScale = $entry['font_scale'] ?? 100;
            $spacingScale = $entry['spacing_scale'] ?? 100;
            $rawLayout = $entry['layout'] ?? 'auto';

            if (
                !is_string($breakpoint)
                || !is_string($rawTarget)
                || !is_string($rawVisibility)
                || !is_int($fontScale)
                || !is_int($spacingScale)
                || !is_string($rawLayout)
            ) {
                throw new InvalidArgumentException('Responsive rule fields are invalid.');
            }

            $target = ResponsiveTarget::tryFrom($rawTarget);
            $visibility = ResponsiveVisibility::tryFrom($rawVisibility);
            $layout = ResponsiveLayout::tryFrom($rawLayout);
            if (
                !$target instanceof ResponsiveTarget
                || !$visibility instanceof ResponsiveVisibility
                || !$layout instanceof ResponsiveLayout
            ) {
                throw new InvalidArgumentException('Responsive rule contains an unknown enum value.');
            }

            $rules[] = new ResponsiveRule(
                $breakpoint,
                $target,
                $visibility,
                $fontScale,
                $spacingScale,
                $layout,
            );
        }

        return new self($version, $breakpoints, $rules);
    }

    public function breakpoint(string $key): ResponsiveBreakpoint
    {
        return $this->breakpoints[$key]
            ?? throw new InvalidArgumentException('Unknown responsive breakpoint.');
    }

    /** @return list<ResponsiveBreakpoint> */
    public function breakpoints(): array
    {
        $breakpoints = array_values($this->breakpoints);
        usort(
            $breakpoints,
            static fn (ResponsiveBreakpoint $left, ResponsiveBreakpoint $right): int =>
                $left->minWidth <=> $right->minWidth,
        );

        return $breakpoints;
    }

    /** @return list<ResponsiveRule> */
    public function rules(): array
    {
        return $this->rules;
    }
}
