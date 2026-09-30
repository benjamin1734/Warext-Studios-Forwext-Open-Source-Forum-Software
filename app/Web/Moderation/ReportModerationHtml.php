<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Report\ReportComment;
use Forwext\Core\Moderation\Report\ReportGroup;
use Forwext\Core\Moderation\Report\ReportStatus;
use Forwext\Core\Moderation\Report\ReportSubmission;
use Forwext\Core\Routing\BasePath;

final class ReportModerationHtml
{
    /**
     * @param list<ReportSubmission> $submissions
     * @param list<ReportComment> $comments
     */
    public static function page(
        ReportGroup $group,
        array $submissions,
        array $comments,
        BasePath $basePath,
        bool $canManage,
    ): string {
        $back = self::e($basePath->prepend('/moderation#moderation-reports'));
        $content = '<section class="moderation-subpage discovery-page"><header class="surface-head moderation-subpage-head"><div>'
            . '<span class="forum-eyebrow">' . self::e($group->targetType) . ' · RAPOR</span><h1>'
            . self::e($group->targetTitle) . '</h1><p>' . self::e($group->reasonLabel) . ' · '
            . self::e($group->status->label()) . ' · ' . $group->reportCount . ' rapor</p></div>'
            . '<a class="fx-btn" href="' . $back . '">Çalışma alanına dön</a></header>'
            . '<section class="surface-panel moderation-report-meta"><div class="profile-stats">'
            . self::stat('Hedef', self::e($group->targetType . ' / ' . $group->targetId->value()))
            . self::stat('Durum', self::e($group->status->label()))
            . self::stat('Atanan', self::e($group->assignedModeratorUserId?->value() ?? 'Atanmamış'))
            . self::stat('Son rapor', self::e($group->latestReportAt->format('Y-m-d H:i') . ' UTC'))
            . '</div></section>';

        if ($canManage && $group->status->isActive()) {
            $content .= self::managementForms($group, $basePath);
        }

        $reportRows = '';
        foreach ($submissions as $submission) {
            $detail = trim($submission->detail) === '' ? '<span class="muted">Açıklama verilmedi.</span>' : nl2br(self::e($submission->detail));
            $reportRows .= '<article class="moderation-note"><span class="moderation-row-type">Rapor</span>'
                . '<div class="muted">Raporlayan: ' . self::e($submission->reporterUserId?->value() ?? 'Silinmiş kullanıcı')
                . ' · ' . self::e($submission->createdAt->format('Y-m-d H:i')) . ' UTC</div>'
                . '<div class="moderation-note-body">' . $detail . '</div></article>';
        }
        if ($reportRows === '') {
            $reportRows = '<div class="surface-empty"><strong>Rapor kaydı yok.</strong><span>Bu grupta tekil rapor bulunamadı.</span></div>';
        }
        $content .= '<section class="surface-panel moderation-report-panel"><header><h2>Tekil raporlar</h2><span>'
                . count($submissions) . '</span></header><div class="moderation-list">' . $reportRows . '</div></section>';

        $commentRows = '';
        foreach ($comments as $comment) {
            $commentRows .= '<article class="moderation-note is-staff"><span class="moderation-row-type">Moderatör notu</span>'
                . '<div class="muted">Yetkili: ' . self::e($comment->moderatorUserId?->value() ?? 'Silinmiş kullanıcı')
                . ' · ' . self::e($comment->createdAt->format('Y-m-d H:i')) . ' UTC</div>'
                . '<div class="moderation-note-body">' . nl2br(self::e($comment->body)) . '</div></article>';
        }
        if ($commentRows === '') {
            $commentRows = '<div class="surface-empty"><strong>Henüz moderatör notu yok.</strong><span>Yeni notlar burada görünecek.</span></div>';
        }
        $content .= '<section class="surface-panel moderation-report-panel"><header><h2>Moderatör notları</h2><span>'
                . count($comments) . '</span></header><div class="moderation-list">' . $commentRows . '</div></section>';

        if ($canManage) {
            $action = self::e($basePath->prepend('/moderation/reports/' . rawurlencode($group->groupId->value()) . '/comments'));
            $content .= '<section class="surface-panel moderation-note-form"><h2>İç not ekle</h2>'
                . '<form class="search-form" method="post" action="' . $action . '" data-moderation-form>'
                . '<label class="search-wide"><span>Not</span><textarea name="body" maxlength="4000" rows="5" required></textarea></label>'
                . '<div class="search-actions"><button type="submit">Notu ekle</button></div></form></section>';
        }

        $content .= '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script></section>';
        return ProfileHtml::page('Rapor inceleme', $content, $basePath, authenticated: true);
    }

    private static function managementForms(ReportGroup $group, BasePath $basePath): string
    {
        $root = '/moderation/reports/' . rawurlencode($group->groupId->value());
        $assign = self::e($basePath->prepend($root . '/assign'));
        $status = self::e($basePath->prepend($root . '/status'));
        $assignee = self::e($group->assignedModeratorUserId?->value() ?? '');

        return '<div class="moderation-control-grid">'
            . '<section class="surface-panel moderation-control-card"><h2>Atama</h2><form class="search-form" method="post" action="' . $assign . '" data-moderation-form>'
            . '<label class="search-wide"><span>Moderatör kullanıcı ID (boş = atamayı kaldır)</span>'
            . '<input name="assignee_user_id" maxlength="32" value="' . $assignee . '"></label>'
            . '<div class="search-actions"><button type="submit">Atamayı güncelle</button></div></form></section>'
            . '<section class="surface-panel moderation-control-card"><h2>Durum</h2><form class="search-form" method="post" action="' . $status . '" data-moderation-form>'
            . '<label class="search-wide"><span>Yeni durum</span><select name="status">'
            . self::option(ReportStatus::Open, $group->status)
            . self::option(ReportStatus::InReview, $group->status)
            . self::option(ReportStatus::Resolved, $group->status)
            . self::option(ReportStatus::Rejected, $group->status)
            . '</select></label><div class="search-actions"><button type="submit">Durumu güncelle</button></div></form></section>'
            . '</div>';
    }

    private static function stat(string $label, string $value): string
    {
        return '<div><strong>' . $label . '</strong><span>' . $value . '</span></div>';
    }

    private static function option(ReportStatus $status, ReportStatus $current): string
    {
        return '<option value="' . self::e($status->value) . '"' . ($status === $current ? ' selected' : '') . '>'
            . self::e($status->label()) . '</option>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
