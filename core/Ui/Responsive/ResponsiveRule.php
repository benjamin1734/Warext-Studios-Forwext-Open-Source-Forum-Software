<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

use InvalidArgumentException;

final readonly class ResponsiveRule
{
    public function __construct(
        public string $breakpoint,
        public ResponsiveTarget $target,
        public ResponsiveVisibility $visibility = ResponsiveVisibility::Inherit,
        public int $fontScalePercent = 100,
        public int $spacingScalePercent = 100,
        public ResponsiveLayout $layout = ResponsiveLayout::Auto,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $this->breakpoint) !== 1) {
            throw new InvalidArgumentException('Responsive rule breakpoint key is invalid.');
        }

        if ($this->fontScalePercent < 50 || $this->fontScalePercent > 200) {
            throw new InvalidArgumentException('Responsive font scale must be between 50 and 200 percent.');
        }

        if ($this->spacingScalePercent < 25 || $this->spacingScalePercent > 250) {
            throw new InvalidArgumentException('Responsive spacing scale must be between 25 and 250 percent.');
        }
    }
}
