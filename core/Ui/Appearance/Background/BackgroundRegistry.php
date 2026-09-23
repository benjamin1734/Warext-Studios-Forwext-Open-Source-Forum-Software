<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class BackgroundRegistry
{
    /** @var array<string, BackgroundDefinition> */
    private array $definitions;

    /**
     * @param list<BackgroundDefinition> $definitions
     */
    public function __construct(
        public readonly int $manifestVersion,
        private readonly DesignTokenCatalog $tokens,
        array $definitions,
    ) {
        if ($this->manifestVersion !== 1) {
            throw new InvalidArgumentException('Unsupported background manifest version.');
        }

        $indexed = [];
        $scopeKeys = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw new InvalidArgumentException('Duplicate background key: ' . $definition->key);
            }

            $scopeKey = $definition->scope->value . ':' . ($definition->scopeId ?? '*');
            if (isset($scopeKeys[$scopeKey])) {
                throw new InvalidArgumentException('Duplicate active background scope: ' . $scopeKey);
            }

            foreach ($definition->colorTokens as $tokenKey) {
                $resolved = $this->tokens->resolveValue($tokenKey);
                if (preg_match('/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/D', $resolved) !== 1) {
                    throw new InvalidArgumentException(
                        'Background color reference must resolve to a color token.',
                    );
                }
            }

            $indexed[$definition->key] = $definition;
            $scopeKeys[$scopeKey] = true;
        }

        ksort($indexed, SORT_STRING);
        $this->definitions = $indexed;
    }

    public static function coreDefaults(DesignTokenCatalog $tokens): self
    {
        $path = dirname(__DIR__, 4) . '/resources/appearance/forwext-backgrounds-default.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Core background manifest could not be read.');
        }

        return self::fromJson($json, $tokens);
    }

    public static function fromJson(string $json, DesignTokenCatalog $tokens): self
    {
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Background manifest is not valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($manifest)) {
            throw new InvalidArgumentException('Background manifest must be an object.');
        }

        $version = $manifest['version'] ?? null;
        $rows = $manifest['backgrounds'] ?? null;
        if (!is_int($version) || !is_array($rows) || !array_is_list($rows)) {
            throw new InvalidArgumentException('Background manifest shape is invalid.');
        }

        $definitions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Background manifest entry must be an object.');
            }

            $key = $row['key'] ?? null;
            $rawScope = $row['scope'] ?? null;
            $scopeId = $row['scope_id'] ?? null;
            $rawKind = $row['kind'] ?? null;
            $colors = $row['colors'] ?? [];
            $rawAsset = $row['asset'] ?? null;
            $rawPattern = $row['pattern'] ?? null;
            $opacity = $row['opacity'] ?? 100;
            $scale = $row['scale'] ?? 100;
            $rotation = $row['rotation'] ?? 0;
            $rawBlend = $row['blend'] ?? 'normal';
            $animated = $row['animated'] ?? false;
            $duration = $row['animation_duration_ms'] ?? 12000;

            if (
                !is_string($key)
                || !is_string($rawScope)
                || ($scopeId !== null && !is_string($scopeId))
                || !is_string($rawKind)
                || !is_array($colors)
                || !array_is_list($colors)
                || ($rawAsset !== null && !is_string($rawAsset))
                || ($rawPattern !== null && !is_string($rawPattern))
                || !is_int($opacity)
                || !is_int($scale)
                || !is_int($rotation)
                || !is_string($rawBlend)
                || !is_bool($animated)
                || !is_int($duration)
            ) {
                throw new InvalidArgumentException('Background manifest entry fields are invalid.');
            }

            $typedColors = [];
            foreach ($colors as $color) {
                if (!is_string($color)) {
                    throw new InvalidArgumentException('Background color list is invalid.');
                }
                $typedColors[] = $color;
            }

            $scope = BackgroundScope::tryFrom($rawScope);
            $kind = BackgroundKind::tryFrom($rawKind);
            $blend = BackgroundBlendMode::tryFrom($rawBlend);
            $pattern = $rawPattern === null ? null : BackgroundPattern::tryFrom($rawPattern);
            if (
                !$scope instanceof BackgroundScope
                || !$kind instanceof BackgroundKind
                || !$blend instanceof BackgroundBlendMode
                || ($rawPattern !== null && !$pattern instanceof BackgroundPattern)
            ) {
                throw new InvalidArgumentException('Background manifest contains an unknown enum value.');
            }

            $definitions[] = new BackgroundDefinition(
                $key,
                $scope,
                $scopeId,
                $kind,
                $typedColors,
                $rawAsset === null ? null : BackgroundAssetPath::fromString($rawAsset),
                $pattern,
                $opacity,
                $scale,
                $rotation,
                $blend,
                $animated,
                $duration,
            );
        }

        return new self($version, $tokens, $definitions);
    }

    /** @return list<BackgroundDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }
}
