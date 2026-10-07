<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\Core\Routing\BasePath;

final class AnalyticsAdminNavHtml
{
    public static function header(
        BasePath $basePath,
        string $active,
        string $title,
        string $description,
        ?int $windowDays = null,
        ?string $rangePath = null,
    ): string {
        $items = [
            'overview' => ['Genel', '/admin/analytics'],
            'content' => ['İçerik', '/admin/analytics/content'],
            'operations' => ['Operasyon', '/admin/analytics/operations'],
            'commerce' => ['Commerce', '/admin/analytics/commerce'],
            'reports' => ['Raporlar', '/admin/analytics/reports'],
            'history' => ['Kullanıcı geçmişi', '/admin/users'],
        ];

        $tabs = '<nav class="analytics-tabs" aria-label="Analytics yönetimi">';
        foreach ($items as $key => [$label, $path]) {
            $tabs .= '<a href="' . self::e($basePath->prepend($path)) . '"'
                . ($active === $key ? ' aria-current="page"' : '') . '>'
                . self::e($label) . '</a>';
        }
        $tabs .= '</nav>';

        $range = '';
        if ($windowDays !== null && $rangePath !== null) {
            $range = '<nav class="analytics-range" aria-label="Analytics zaman aralığı">';
            foreach ([7, 30, 90] as $days) {
                $range .= '<a href="' . self::e($basePath->prepend($rangePath) . '?days=' . $days) . '"'
                    . ($windowDays === $days ? ' aria-current="page"' : '') . '>'
                    . $days . ' gün</a>';
            }
            $range .= '</nav>';
        }

        return '<section class="card analytics-head"><div><span class="analytics-kicker">ACP OBSERVABILITY</span>'
            . '<h1 class="acp-title-reset">' . self::e($title) . '</h1><p class="muted">'
            . self::e($description) . '</p></div>' . $tabs . $range . '</section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
