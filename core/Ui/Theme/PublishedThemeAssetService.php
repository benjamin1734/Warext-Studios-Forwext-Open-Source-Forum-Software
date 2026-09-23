<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Theme;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PublishedThemeAssetService
{
    public function __construct(
        private ThemeRepository $themes,
        private ThemeTemplateCache $cache,
    ) {
    }

    public function asset(string $themeKey, EntityId $revisionId, string $kind): string
    {
        $theme = $this->themes->findByKey($themeKey)
            ?? throw new InvalidArgumentException('Theme was not found.');
        if (
            $theme->publishedRevisionId === null
            || !$theme->publishedRevisionId->equals($revisionId)
        ) {
            throw new InvalidArgumentException('Theme asset revision is not currently published.');
        }

        return $this->cache->asset($themeKey, $revisionId, $kind);
    }
}
