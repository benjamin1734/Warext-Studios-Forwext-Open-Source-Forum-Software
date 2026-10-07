<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Routing\BasePath;

final class AdminPlatformNavHtml
{
    public static function render(BasePath $basePath, string $active): string
    {
        $items = [
            'navigation' => ['Navigasyon', '/admin/navigation'],
            'modules' => ['Modüller', '/admin/modules'],
            'integrations' => ['Entegrasyonlar', '/admin/integrations'],
        ];

        $html = '<nav class="platform-tabs" aria-label="Platform yönetimi">';
        foreach ($items as $key => [$label, $path]) {
            $html .= '<a class="platform-tab" href="' . self::escape($basePath->prepend($path)) . '"'
                . ($active === $key ? ' aria-current="page"' : '') . '>'
                . self::escape($label) . '</a>';
        }

        return $html . '</nav>';
    }

    public static function stat(string $label, int $value, string $detail): string
    {
        return '<article class="platform-stat"><span>' . self::escape($label) . '</span><strong>'
            . $value . '</strong><small>' . self::escape($detail) . '</small></article>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
