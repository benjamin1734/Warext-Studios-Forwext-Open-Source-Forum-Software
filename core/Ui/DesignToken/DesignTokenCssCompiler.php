<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\DesignToken;

use RuntimeException;

final class DesignTokenCssCompiler
{
    public function compile(DesignTokenCatalog $catalog): string
    {
        $declarations = [];
        $reducedMotion = [];

        foreach ($catalog->definitions() as $definition) {
            $value = $definition->reference === null
                ? $definition->value
                : 'var(' . $catalog->definition($definition->reference)->cssVariable() . ')';

            if ($value === null) {
                throw new RuntimeException('Design token CSS value is unavailable.');
            }

            $declarations[] = $definition->cssVariable() . ':' . $value;

            if (
                $definition->category === DesignTokenCategory::Motion
                && str_contains($definition->key, '.duration.')
            ) {
                $reducedMotion[] = $definition->cssVariable() . ':0ms';
            }
        }

        $css = ':root{' . implode(';', $declarations) . '}';

        if ($reducedMotion !== []) {
            $css .= '@media (prefers-reduced-motion: reduce){:root{'
                . implode(';', $reducedMotion)
                . '}}';
        }

        return $css;
    }
}
