<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;

final class ComponentAppearanceCssCompiler
{
    public function compile(
        ComponentAppearanceRegistry $registry,
        DesignTokenCatalog $tokens,
    ): string {
        $declarations = [];

        foreach ($registry->definitions() as $definition) {
            foreach ($definition->bindings as $property => $tokenKey) {
                $declarations[] = '--forwext-component-'
                    . $definition->target->value
                    . '-'
                    . $property
                    . ':var('
                    . $tokens->definition($tokenKey)->cssVariable()
                    . ')';
            }
        }

        return ':root{' . implode(';', $declarations) . '}';
    }
}
