<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Intake\BugAttachmentRecord;
use Forwext\Core\Bug\Intake\BugReportIntake;
use Forwext\Core\Bug\Report\BugHistoryEventType;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Routing\BasePath;

final class BugReportDetailHtml
{
    /**
     * @param list<BugAttachmentRecord> $attachments
     * @param list<BugReportHistoryEntry> $history
     */
    public static function page(
        BugReport $report,
        ?BugReportIntake $intake,
        array $attachments,
        array $history,
        string $csrfToken,
        BasePath $basePath,
        bool $canAddInfo,
        bool $canStaffRespond,
        bool $updated = false,
    ): string {
        $body = '<section class="card settings"><h1>' . self::e($report->title) . '</h1>'
            . '<p><a href="' . self::e($basePath->prepend('/bugs/my')) . '">Hata Bildirimlerim</a></p>'
            . ($updated ? '<div class="notice success">Hata bildirimi güncellendi.</div>' : '')
            . '<div class="search-hit-type">' . self::e($report->categoryKey)
            . ' · ' . self::e($report->status->label())
            . ' · ' . self::e($report->severity->label()) . '</div>'
            . '<p>' . nl2br(self::e($report->summary)) . '</p>'
            . '<p class="muted">Kayıt: <code>' . self::e($report->reportId->value()) . '</code>'
            . ' · Oluşturuldu: ' . self::e($report->createdAt->format('Y-m-d H:i'))
            . ' · Güncellendi: ' . self::e($report->updatedAt->format('Y-m-d H:i')) . '</p></section>';

        if ($intake !== null) {
            $body .= '<section class="card section"><h2>Teknik açıklama</h2>'
                . self::textBlock('Tekrar üretme adımları', $intake->reproductionSteps)
                . self::textBlock('Beklenen sonuç', $intake->expectedResult)
                . self::textBlock('Gerçekleşen sonuç', $intake->actualResult)
                . ($intake->reportedSourcePath === null
                    ? ''
                    : '<p><strong>Kaynak sayfa</strong><br><code>' . self::e($intake->reportedSourcePath) . '</code></p>')
                . '</section>';
        }

        $body .= '<section class="card section"><h2>Ek dosyalar</h2>';
        if ($attachments === []) {
            $body .= '<p class="muted">Bu bildirimde ek dosya yok.</p>';
        } else {
            $body .= '<ul>';
            foreach ($attachments as $attachment) {
                $href = $basePath->prepend(
                    '/bugs/' . rawurlencode($report->reportId->value())
                    . '/attachments/' . rawurlencode($attachment->attachmentId->value()),
                );
                $body .= '<li><a href="' . self::e($href) . '">' . self::e($attachment->filename->value()) . '</a>'
                    . ' <span class="muted">(' . self::e($attachment->mediaType) . ', '
                    . self::e((string) $attachment->sizeBytes) . ' bayt)</span></li>';
            }
            $body .= '</ul>';
        }
        $body .= '</section>';

        $body .= '<section class="card section"><h2>Yanıtlar ve geçmiş</h2>';
        if ($history === []) {
            $body .= '<div class="empty">Henüz geçmiş kaydı yok.</div>';
        } else {
            foreach ($history as $entry) {
                $body .= self::historyEntry($entry);
            }
        }
        $body .= '</section>';

        if ($canAddInfo) {
            $body .= self::replyForm(
                $report,
                $csrfToken,
                $basePath,
                'additional_info',
                'Ek bilgi gönder',
                'Yeni gözlem, tekrar üretme ayrıntısı veya düzeltme bilgisi ekleyin.',
            );
        }
        if ($canStaffRespond) {
            $body .= self::replyForm(
                $report,
                $csrfToken,
                $basePath,
                'staff_response',
                'Yetkili yanıtı',
                'Kullanıcının görebileceği bir yanıt yazın.',
            );
        }

        $body .= '<section class="card section"><p class="muted">'
            . 'Durum değişiklikleri ve yetkili yanıtları bildirim motoru üzerinden hesabınıza iletilir.'
            . '</p></section>';

        return ProfileHtml::page('Hata Bildirimi', $body, $basePath, authenticated: true);
    }

    private static function historyEntry(BugReportHistoryEntry $entry): string
    {
        $label = match ($entry->eventType) {
            BugHistoryEventType::Created => 'Hata bildirimi oluşturuldu',
            BugHistoryEventType::StatusChanged => 'Durum güncellendi',
            BugHistoryEventType::Assigned => 'Yetkili ataması güncellendi',
            BugHistoryEventType::SeverityChanged => 'Önem seviyesi güncellendi',
            BugHistoryEventType::CategoryChanged => 'Kategori güncellendi',
            BugHistoryEventType::ReporterInfoAdded => 'Kullanıcı ek bilgi gönderdi',
            BugHistoryEventType::StaffResponse => 'Yetkili yanıt verdi',
        };

        $detail = '';
        if (in_array($entry->eventType, [BugHistoryEventType::ReporterInfoAdded, BugHistoryEventType::StaffResponse], true)) {
            $value = $entry->payload['body'] ?? '';
            $detail = is_string($value) && $value !== ''
                ? '<p>' . nl2br(self::e($value)) . '</p>'
                : '';
        } elseif ($entry->eventType === BugHistoryEventType::StatusChanged) {
            $from = is_string($entry->payload['from'] ?? null) ? (string) $entry->payload['from'] : '';
            $to = is_string($entry->payload['to'] ?? null) ? (string) $entry->payload['to'] : '';
            $detail = '<p class="muted">' . self::e($from) . ' → ' . self::e($to) . '</p>';
        }

        return '<article class="search-hit"><div class="search-hit-type">' . self::e($label) . '</div>'
            . $detail
            . '<p class="muted">' . self::e($entry->createdAt->format('Y-m-d H:i')) . '</p></article>';
    }

    private static function replyForm(
        BugReport $report,
        string $csrfToken,
        BasePath $basePath,
        string $action,
        string $title,
        string $help,
    ): string {
        return '<section class="card section"><h2>' . self::e($title) . '</h2>'
            . '<form method="post" action="'
            . self::e($basePath->prepend('/bugs/' . rawurlencode($report->reportId->value())))
            . '" class="presence-settings" style="display:grid;align-items:stretch">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<label><span>' . self::e($help) . '</span><textarea name="body" maxlength="10000" rows="6" required></textarea></label>'
            . '<button type="submit">' . self::e($title) . '</button></form></section>';
    }

    private static function textBlock(string $title, string $value): string
    {
        return '<p><strong>' . self::e($title) . '</strong><br>' . nl2br(self::e($value)) . '</p>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
