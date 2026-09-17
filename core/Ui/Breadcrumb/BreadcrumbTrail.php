<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Breadcrumb;

final readonly class BreadcrumbTrail
{
    /** @param list<BreadcrumbItem> $items */
    public function __construct(public array $items)
    {
    }

    public static function page(string $label): self
    {
        return new self([
            new BreadcrumbItem('Ana Sayfa', '/'),
            new BreadcrumbItem($label),
        ]);
    }
}
