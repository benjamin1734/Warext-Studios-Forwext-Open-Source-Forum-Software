<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

final readonly class RegisteredWidget
{
    public function __construct(
        public WidgetOwnerType $ownerType,
        public string $ownerKey,
        public Widget $widget,
    ) {
    }
}
