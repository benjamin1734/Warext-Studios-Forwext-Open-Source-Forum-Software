<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use RuntimeException;

final class BackgroundCssCompiler
{
    public function compile(
        BackgroundRegistry $registry,
        DesignTokenCatalog $tokens,
        BasePath $basePath,
    ): string {
        $rules = [];
        $animations = [];

        foreach ($registry->definitions() as $definition) {
            $selector = $this->selector($definition);
            $layer = $this->layer($definition, $tokens, $basePath);
            $animation = '';

            if ($definition->kind === BackgroundKind::Gradient && $definition->animatedGradient) {
                $animationName = 'forwext-bg-' . substr(hash('sha256', $definition->key), 0, 12);
                $animation = 'animation:' . $animationName . ' '
                    . $definition->animationDurationMs . 'ms linear infinite;';
                $animations[] = '@keyframes ' . $animationName
                    . '{0%{background-position:0% 50%}50%{background-position:100% 50%}'
                    . '100%{background-position:0% 50%}}'
                    . '@media (prefers-reduced-motion: reduce){'
                    . $selector . '::before{animation:none!important}}';
            }

            $inset = $definition->scope === BackgroundScope::Site ? '-25vmax' : '-25%';
            $position = $definition->scope === BackgroundScope::Site ? 'fixed' : 'absolute';
            $size = max($definition->scalePercent, $definition->animatedGradient ? 200 : 25);

            $rules[] = $selector
                . '{position:relative;isolation:isolate}'
                . $selector
                . '::before{content:"";position:' . $position . ';inset:' . $inset
                . ';z-index:0;pointer-events:none;opacity:'
                . self::decimalPercent($definition->opacityPercent)
                . ';transform:scale(' . self::decimalPercent($definition->scalePercent)
                . ') rotate(' . $definition->rotationDegrees . 'deg);transform-origin:center;'
                . 'background-blend-mode:' . $definition->blendMode->value . ';'
                . 'background-size:' . $size . '% ' . $size . '%;background-position:center;'
                . $layer . $animation . '}'
                . $this->foregroundRule($definition, $selector);
        }

        return implode('', [...$rules, ...$animations]);
    }

    private function selector(BackgroundDefinition $definition): string
    {
        return match ($definition->scope) {
            BackgroundScope::Site => 'body[data-forwext-background-scope="site"]',
            BackgroundScope::Header => '[data-forwext-background-scope="header"]',
            BackgroundScope::Category => '[data-forwext-background-scope="category"]'
                . '[data-forwext-background-id="' . $definition->scopeId . '"]',
            BackgroundScope::Profile => '[data-forwext-background-scope="profile"]'
                . '[data-forwext-background-id="' . $definition->scopeId . '"]',
        };
    }

    private function foregroundRule(BackgroundDefinition $definition, string $selector): string
    {
        if ($definition->scope === BackgroundScope::Site) {
            return $selector . '>header,' . $selector . '>main,'
                . $selector . '>.layout-shell,' . $selector . '>footer,'
                . $selector . '>.ui-page-slot{position:relative;z-index:1}';
        }

        return $selector . '>*{position:relative;z-index:1}';
    }

    private function layer(
        BackgroundDefinition $definition,
        DesignTokenCatalog $tokens,
        BasePath $basePath,
    ): string {
        $colors = array_map(
            fn (string $token): string => 'var(' . $tokens->definition($token)->cssVariable() . ')',
            $definition->colorTokens,
        );

        return match ($definition->kind) {
            BackgroundKind::Solid => 'background:' . $colors[0] . ';',
            BackgroundKind::Gradient => 'background-image:linear-gradient(135deg,'
                . implode(',', $colors) . ');',
            BackgroundKind::Image => $this->imageLayer($definition, $basePath),
            BackgroundKind::Pattern => $this->patternLayer($definition, $colors),
        };
    }

    private function imageLayer(BackgroundDefinition $definition, BasePath $basePath): string
    {
        if (!$definition->asset instanceof BackgroundAssetPath) {
            throw new RuntimeException('Image background asset is unavailable.');
        }

        $url = $basePath->prepend('/' . $definition->asset->value());

        return 'background-image:url("' . $url . '");background-repeat:no-repeat;';
    }

    /** @param list<string> $colors */
    private function patternLayer(BackgroundDefinition $definition, array $colors): string
    {
        $primary = $colors[0] ?? 'currentColor';
        $secondary = $colors[1] ?? 'transparent';

        return match ($definition->pattern) {
            BackgroundPattern::Dots => 'background-image:radial-gradient('
                . $primary . ' 1px,' . $secondary . ' 1px);background-size:12px 12px;',
            BackgroundPattern::Grid => 'background-image:linear-gradient('
                . $primary . ' 1px,transparent 1px),linear-gradient(90deg,'
                . $primary . ' 1px,' . $secondary . ' 1px);background-size:16px 16px;',
            BackgroundPattern::Diagonal => 'background-image:repeating-linear-gradient(135deg,'
                . $primary . ' 0 6px,' . $secondary . ' 6px 12px);',
            BackgroundPattern::Checker => 'background-image:conic-gradient('
                . $primary . ' 25%,' . $secondary . ' 0 50%,' . $primary
                . ' 0 75%,' . $secondary . ' 0);background-size:20px 20px;',
            null => throw new RuntimeException('Pattern background is missing its pattern type.'),
        };
    }

    private static function decimalPercent(int $percent): string
    {
        $value = number_format($percent / 100, 2, '.', '');
        return rtrim(rtrim($value, '0'), '.');
    }
}
