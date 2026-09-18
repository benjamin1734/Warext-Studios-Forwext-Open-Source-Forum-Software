<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Routing\BasePath;

final class MyBugReportsHtml
{
    /** @param list<BugReport> $reports */
    public static function page(array $reports, BasePath $basePath): string
    {
        $body = '<section class="card settings"><h1>Hata Bildirimlerim</h1>'
            . '<p><a href="' . self::e($basePath->prepend('/bugs/report')) . '">Yeni hata bildir</a></p>'
            . '<p class="muted">Yalnız kendi hesabınızla oluşturduğunuz hata bildirimleri listelenir.</p></section>';

        $body .= '<section class="card section"><h2>Bildirim geçmişi</h2>';
        if ($reports === []) {
            $body .= '<div class="empty">Henüz hata bildiriminiz yok.</div></section>';
            return ProfileHtml::page('Hata Bildirimlerim', $body, $basePath, authenticated: true);
        }

        foreach ($reports as $report) {
            $href = $basePath->prepend('/bugs/' . rawurlencode($report->reportId->value()));
            $body .= '<article class="search-hit"><div class="search-hit-type">'
                . self::e($report->categoryKey) . ' · ' . self::e($report->status->label())
                . ' · ' . self::e($report->severity->label()) . '</div>'
                . '<h3><a href="' . self::e($href) . '">' . self::e($report->title) . '</a></h3>'
                . '<p class="muted">Oluşturuldu: ' . self::e($report->createdAt->format('Y-m-d H:i'))
                . ' · Güncellendi: ' . self::e($report->updatedAt->format('Y-m-d H:i')) . '</p></article>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Hata Bildirimlerim', $body, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
