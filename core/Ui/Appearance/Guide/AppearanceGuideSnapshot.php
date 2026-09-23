<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Guide;

final readonly class AppearanceGuideSnapshot
{
    /**
     * @param list<AppearancePreset> $presets
     * @param list<AppearanceGuideItem> $items
     * @param array<string,bool> $itemAccess
     */
    public function __construct(
        public AppearanceGuideLevel $mode,
        public AppearancePreviewDevice $device,
        public string $search,
        public AppearancePreset $selectedPreset,
        public array $presets,
        public array $items,
        public array $itemAccess,
        public bool $advancedAllowed,
    ) {
    }

    public function isAccessible(AppearanceGuideItem $item): bool
    {
        return $this->itemAccess[$item->key] ?? false;
    }
}
