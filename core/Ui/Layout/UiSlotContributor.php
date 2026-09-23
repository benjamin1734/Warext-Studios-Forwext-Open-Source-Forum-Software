<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout;

interface UiSlotContributor
{
    public function registerSlots(UiSlotRegistry $registry): void;
}
