<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Routing\BasePath;

final class AdminPlatformNavigationHtml
{
    public static function render(BasePath $basePath, string $active): string
    {
        $items = [
            'modules' => ['/admin/modules', 'Modüller'],
            'navigation' => ['/admin/navigation', 'Navigasyon'],
            'integrations' => ['/admin/integrations', 'Entegrasyonlar'],
        ];

        $html = '<nav class="platform-admin-nav" aria-label="Platform yönetimi">';
        foreach ($items as $key => [$path, $label]) {
            $html .= '<a href="' . self::escape($basePath->prepend($path)) . '"'
                . ($active === $key ? ' aria-current="page"' : '') . '>'
                . self::escape($label) . '</a>';
        }

        return $html . '</nav>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
