<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

final readonly class ThemeDiffEntry
{
    public function __construct(
        public string $path,
        public ?string $before,
        public ?string $after,
    ) {
    }
}
