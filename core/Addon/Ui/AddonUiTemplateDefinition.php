<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Ui\Theme\ThemeTemplateCompiler;

final readonly class AddonUiTemplateDefinition
{
    public function __construct(
        public string $key,
        public string $source,
    ) {
        (new ThemeTemplateCompiler())->compile($this->key, $this->source);
    }
}
