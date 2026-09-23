<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

final readonly class LayoutBuilderSnapshot
{
    public function __construct(
        public string $layoutKey,
        public ?LayoutRevision $draft,
        public ?LayoutRevision $published,
    ) {
    }
}
