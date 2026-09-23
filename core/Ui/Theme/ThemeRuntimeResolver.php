<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use InvalidArgumentException;

final readonly class ThemeRuntimeResolver
{
    public function __construct(private ThemeRepository $themes)
    {
    }

    public function effectivePublishedPayload(string $themeKey): ThemePayload
    {
        $visited = [];
        return $this->resolve($themeKey, $visited, 0);
    }

    /** @param array<string,true> $visited */
    private function resolve(string $themeKey, array &$visited, int $depth): ThemePayload
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Theme inheritance depth exceeds the supported limit.');
        }

        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');
        if (isset($visited[$theme->themeId->value()])) {
            throw new InvalidArgumentException('Theme inheritance cycle detected.');
        }
        $visited[$theme->themeId->value()] = true;

        $revisionId = $theme->publishedRevisionId
            ?? throw new InvalidArgumentException('Theme is not published.');
        $revision = $this->themes->revision($revisionId)
            ?? throw new InvalidArgumentException('Published theme revision was not found.');

        if ($theme->parentThemeId === null) {
            return $revision->payload;
        }

        $parent = $this->themes->findById($theme->parentThemeId)
            ?? throw new InvalidArgumentException('Parent theme was not found.');
        $parentPayload = $this->resolve($parent->key, $visited, $depth + 1);

        return ThemePayload::merge($parentPayload, $revision->payload);
    }
}
