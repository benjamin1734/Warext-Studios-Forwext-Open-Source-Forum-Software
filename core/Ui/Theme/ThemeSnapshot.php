<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

final readonly class ThemeSnapshot
{
    /** @param list<ThemeRevision> $history */
    public function __construct(
        public ThemeDefinition $theme,
        public ?ThemeRevision $staging,
        public ?ThemeRevision $published,
        public array $history,
    ) {
    }
}
