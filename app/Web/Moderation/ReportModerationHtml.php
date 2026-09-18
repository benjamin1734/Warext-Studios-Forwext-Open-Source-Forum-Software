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
        $content = '<div class="card"><a href="' . $back . '">← Moderasyon çalışma alanı</a>'
            . '<h1>' . self::e($group->targetTitle) . '</h1>'
            . '<div class="muted">Hedef: ' . self::e($group->targetType) . ' / ' . self::e($group->targetId->value()) . '</div>'
            . '<p>Neden: <strong>' . self::e($group->reasonLabel) . '</strong></p>'
            . '<p>Durum: <strong>' . self::e($group->status->label()) . '</strong> · Toplam rapor: <strong>' . $group->reportCount . '</strong></p>'
            . '<p class="muted">Atanan: ' . self::e($group->assignedModeratorUserId?->value() ?? 'Atanmamış')
            . ' · Son rapor: ' . self::e($group->latestReportAt->format('Y-m-d H:i')) . ' UTC</p></div>';

        if ($canManage && $group->status->isActive()) {
            $content .= self::managementForms($group, $basePath);
        }

        $reportRows = '';
        foreach ($submissions as $submission) {
            $detail = trim($submission->detail) === '' ? '<span class="muted">Açıklama verilmedi.</span>' : nl2br(self::e($submission->detail));
            $reportRows .= '<article class="search-hit"><span class="search-hit-type">Rapor</span>'
                . '<div class="muted">Raporlayan: ' . self::e($submission->reporterUserId?->value() ?? 'Silinmiş kullanıcı')
                . ' · ' . self::e($submission->createdAt->format('Y-m-d H:i')) . ' UTC</div>'
                . '<div style="margin-top:8px">' . $detail . '</div></article>';
        }
        if ($reportRows === '') {
            $reportRows = '<div class="empty">Bu grupta rapor kaydı bulunamadı.</div>';
        }
        $content .= '<section class="card section"><h2>Tekil raporlar (' . count($submissions) . ')</h2>' . $reportRows . '</section>';

        $commentRows = '';
        foreach ($comments as $comment) {
            $commentRows .= '<article class="search-hit"><span class="search-hit-type">Moderatör notu</span>'
                . '<div class="muted">Yetkili: ' . self::e($comment->moderatorUserId?->value() ?? 'Silinmiş kullanıcı')
                . ' · ' . self::e($comment->createdAt->format('Y-m-d H:i')) . ' UTC</div>'
                . '<div style="margin-top:8px">' . nl2br(self::e($comment->body)) . '</div></article>';
        }
        if ($commentRows === '') {
            $commentRows = '<div class="empty">Henüz moderatör notu yok.</div>';
        }
        $content .= '<section class="card section"><h2>Moderatör notları (' . count($comments) . ')</h2>' . $commentRows . '</section>';

        if ($canManage) {
            $action = self::e($basePath->prepend('/moderation/reports/' . rawurlencode($group->groupId->value()) . '/comments'));
            $content .= '<section class="card section"><h2>İç not ekle</h2>'
                . '<form class="search-form" method="post" action="' . $action . '" data-moderation-form>'
                . '<label class="search-wide"><span>Not</span><textarea name="body" maxlength="4000" rows="5" required></textarea></label>'
                . '<div class="search-actions"><button type="submit">Notu ekle</button></div></form></section>';
        }

        $content .= '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';
        return ProfileHtml::page('Rapor inceleme', $content, $basePath, authenticated: true);
    }

    private static function managementForms(ReportGroup $group, BasePath $basePath): string
    {
        $root = '/moderation/reports/' . rawurlencode($group->groupId->value());
        $assign = self::e($basePath->prepend($root . '/assign'));
        $status = self::e($basePath->prepend($root . '/status'));
        $assignee = self::e($group->assignedModeratorUserId?->value() ?? '');

        return '<div class="grid section">'
            . '<section class="card"><h2>Atama</h2><form class="search-form" method="post" action="' . $assign . '" data-moderation-form>'
            . '<label class="search-wide"><span>Moderatör kullanıcı ID (boş = atamayı kaldır)</span>'
            . '<input name="assignee_user_id" maxlength="32" value="' . $assignee . '"></label>'
            . '<div class="search-actions"><button type="submit">Atamayı güncelle</button></div></form></section>'
            . '<section class="card"><h2>Durum</h2><form class="search-form" method="post" action="' . $status . '" data-moderation-form>'
            . '<label class="search-wide"><span>Yeni durum</span><select name="status">'
            . self::option(ReportStatus::Open, $group->status)
            . self::option(ReportStatus::InReview, $group->status)
            . self::option(ReportStatus::Resolved, $group->status)
            . self::option(ReportStatus::Rejected, $group->status)
            . '</select></label><div class="search-actions"><button type="submit">Durumu güncelle</button></div></form></section>'
            . '</div>';
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
