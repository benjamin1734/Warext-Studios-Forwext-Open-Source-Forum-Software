<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;

final class AdminAssetsHtml
{
    public static function breadcrumbTrail(string $label): BreadcrumbTrail
    {
        return new BreadcrumbTrail([
            new BreadcrumbItem('Administration', '/admin'),
            new BreadcrumbItem($label),
        ]);
    }

    public static function headAssets(BasePath $basePath): string
    {
        $href = htmlspecialchars(
            $basePath->prepend('/assets/admin.css'),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return '<link rel="stylesheet" href="' . $href . '">';
    }
}
