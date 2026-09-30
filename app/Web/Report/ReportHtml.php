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

        $form = '<section class="report-form-page discovery-page"><header class="surface-head report-form-head"><div>'
            . '<span class="forum-eyebrow">RAPOR</span><h1>İçeriği raporla</h1>'
            . '<p>Bu rapor yalnızca moderasyon ekibi tarafından incelenir.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/reports')) . '">Raporlarım</a></header>'
            . '<section class="surface-panel report-target"><span>Raporlanan içerik</span><strong>'
            . self::e($content->title) . '</strong><small>' . self::e($content->targetType) . '</small></section>'
            . '<section class="surface-panel report-form-panel"><form class="support-intake-form" method="post" action="'
            . self::e($basePath->prepend('/reports')) . '" data-report-form>'
            . '<input type="hidden" name="target_type" value="' . self::e($content->targetType) . '">'
            . '<input type="hidden" name="target_id" value="' . self::e($content->targetId->value()) . '">'
            . '<label><span>Neden</span><select name="reason_key" required>' . $options . '</select></label>'
            . '<label><span>Açıklama (opsiyonel)</span><textarea name="detail" maxlength="2000" rows="6"></textarea></label>'
            . '<div class="support-intake-actions"><button class="fx-btn fx-btn--primary" type="submit">Raporu gönder</button></div>'
            . '</form></section></section>'
            . '<script src="' . self::e($basePath->prepend('/assets/report-workflow.js')) . '" defer></script>';

        return ProfileHtml::page('İçeriği raporla', $form, $basePath, authenticated: true);
    }

    /** @param list<ReportSubmissionSummary> $reports */
    public static function history(array $reports, BasePath $basePath, bool $submitted = false, bool $already = false): string
    {
        $notice = '';
        if ($submitted) {
            $notice = '<div class="notification-settings-notice" role="status">Raporun alındı.</div>';
        } elseif ($already) {
            $notice = '<div class="auth-entry-notice" role="status">Bu aktif vaka için daha önce rapor gönderdin.</div>';
        }

        $rows = '';
        foreach ($reports as $report) {
            $rows .= '<article class="report-history-row"><div><span class="report-history-type">'
                . self::e($report->targetType) . '</span><strong>' . self::e($report->targetTitle) . '</strong>'
                . '<small>' . self::e($report->reasonLabel) . ' · ' . self::e($report->status->label())
                . ' · ' . self::e($report->createdAt->format('Y-m-d H:i')) . ' UTC</small></div></article>';
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Henüz rapor göndermedin.</strong>'
                . '<span>Gönderdiğin içerik raporları burada listelenecek.</span></div>';
        }

        $content = '<section class="report-history-page discovery-page"><header class="surface-head report-history-head"><div>'
            . '<span class="forum-eyebrow">HESAP</span><h1>Raporlarım</h1>'
            . '<p>Gönderdiğin içerik raporlarının güncel durumunu takip et.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a></header>'
            . $notice . '<section class="surface-panel report-history-panel"><div class="report-history-list">'
            . $rows . '</div></section></section>';

        return ProfileHtml::page('Raporlarım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
