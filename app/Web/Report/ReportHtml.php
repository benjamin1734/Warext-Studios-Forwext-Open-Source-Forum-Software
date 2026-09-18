<?php

declare(strict_types=1);

namespace Forwext\App\Web\Report;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Report\ReportReason;
use Forwext\Core\Moderation\Report\ReportSubmissionSummary;
use Forwext\Core\Moderation\Report\ReportableContent;
use Forwext\Core\Routing\BasePath;

final class ReportHtml
{
    /** @param list<ReportReason> $reasons */
    public static function form(ReportableContent $content, array $reasons, BasePath $basePath): string
    {
        $options = '';
        foreach ($reasons as $reason) {
            $options .= '<option value="' . self::e($reason->key) . '">' . self::e($reason->label) . '</option>';
        }
        $form = '<div class="card"><h1 style="margin-top:0">İçeriği raporla</h1>'
            . '<p><strong>' . self::e($content->title) . '</strong></p>'
            . '<p class="muted">Rapor yalnızca moderasyon ekibi tarafından incelenir. Aynı hedef ve neden için aktif raporlar tek vakada gruplanır.</p>'
            . '<form class="search-form" method="post" action="' . self::e($basePath->prepend('/reports')) . '" data-report-form>'
            . '<input type="hidden" name="target_type" value="' . self::e($content->targetType) . '">'
            . '<input type="hidden" name="target_id" value="' . self::e($content->targetId->value()) . '">'
            . '<label class="search-wide"><span>Neden</span><select name="reason_key" required>' . $options . '</select></label>'
            . '<label class="search-wide"><span>Açıklama (opsiyonel)</span><textarea name="detail" maxlength="2000" rows="6"></textarea></label>'
            . '<div class="search-actions"><button type="submit">Raporu gönder</button></div>'
            . '</form></div>'
            . '<script src="' . self::e($basePath->prepend('/assets/report-workflow.js')) . '" defer></script>';
        return ProfileHtml::page('İçeriği raporla', $form, $basePath, authenticated: true);
    }

    /** @param list<ReportSubmissionSummary> $reports */
    public static function history(array $reports, BasePath $basePath, bool $submitted = false, bool $already = false): string
    {
        $notice = '';
        if ($submitted) {
            $notice = '<div class="card section"><strong>Raporunuz alındı.</strong></div>';
        } elseif ($already) {
            $notice = '<div class="card section"><strong>Bu aktif vaka için daha önce rapor gönderdiniz.</strong></div>';
        }
        $rows = '';
        foreach ($reports as $report) {
            $rows .= '<article class="search-hit"><span class="search-hit-type">' . self::e($report->targetType) . '</span>'
                . '<h3>' . self::e($report->targetTitle) . '</h3>'
                . '<div>Neden: ' . self::e($report->reasonLabel) . '</div>'
                . '<div class="muted">Durum: ' . self::e($report->status->label())
                . ' · ' . self::e($report->createdAt->format('Y-m-d H:i')) . ' UTC</div></article>';
        }
        if ($rows === '') {
            $rows = '<div class="empty">Henüz rapor göndermediniz.</div>';
        }
        $content = '<div class="card"><h1 style="margin-top:0">Raporlarım</h1>'
            . '<p class="muted">Gönderdiğiniz içerik raporlarının güncel durumunu burada görebilirsiniz.</p>'
            . $rows . '</div>' . $notice;
        return ProfileHtml::page('Raporlarım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
