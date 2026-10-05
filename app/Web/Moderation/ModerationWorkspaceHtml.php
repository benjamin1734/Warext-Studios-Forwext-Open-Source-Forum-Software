<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceItem;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSection;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSnapshot;
use Forwext\Core\Routing\BasePath;

final class ModerationWorkspaceHtml
{
    public static function page(
        ModerationWorkspaceSnapshot $snapshot,
        BasePath $basePath,
        bool $canManage,
        bool $canViewAudit = false,
    ): string {
        $cards = '';
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $cards .= '<a class="moderation-stat" href="#moderation-' . self::e($section->value) . '">'
                . '<span>' . self::e($section->label()) . '</span><strong>' . $snapshot->count($section)
                . '</strong></a>';
        }

        $sections = '';
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $rows = '';
            foreach ($snapshot->itemsFor($section) as $item) {
                $rows .= self::item($item, $basePath, $canManage);
            }
            if ($rows === '') {
                $rows = '<div class="surface-empty"><strong>Kayıt yok.</strong><span>Bu bölümde işleme alınacak öğe bulunmuyor.</span></div>';
            }
            $sections .= '<section class="surface-panel moderation-section" id="moderation-' . self::e($section->value) . '">'
                . '<header><div><h2>' . self::e($section->label()) . '</h2><p>'
                . $snapshot->count($section) . ' kayıt</p></div></header><div class="moderation-section-list">'
                . $rows . '</div></section>';
        }

        $create = '';
        if ($canManage) {
            $create = '<details class="surface-panel moderation-create"><summary><strong>Yeni moderasyon görevi</strong>'
                . '<span>Manuel takip gerektiren iş için görev oluştur.</span></summary>'
                . '<form class="search-form" method="post" action="' . self::e($basePath->prepend('/moderation/tasks')) . '" data-moderation-form>'
                . '<label class="search-wide"><span>Başlık</span><input name="title" maxlength="200" required></label>'
                . '<label class="search-wide"><span>Açıklama</span><input name="description" maxlength="5000"></label>'
                . '<label><span>Öncelik</span><select name="priority">'
                . '<option value="normal">Normal</option><option value="low">Düşük</option>'
                . '<option value="high">Yüksek</option><option value="urgent">Acil</option></select></label>'
                . '<label><span>Son tarih (UTC, opsiyonel)</span><input type="datetime-local" name="due_at"></label>'
                . '<div class="search-actions"><button type="submit">Görev oluştur</button></div>'
                . '</form></details>';
        }

        $auditLink = $canViewAudit
            ? '<div class="moderation-head-actions"><a class="fx-btn" href="'
                . self::e($basePath->prepend('/moderation/audit')) . '">Audit Stream</a>'
                . '<a class="fx-btn" href="' . self::e($basePath->prepend('/moderation/oversight'))
                . '">Bağımsız Denetim</a></div>'
            : '';
        $script = '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';
        $content = '<section class="moderation-workspace discovery-page"><header class="surface-head moderation-head"><div>'
            . '<span class="forum-eyebrow">MODERASYON</span><h1>Çalışma alanı</h1>'
            . '<p>Raporlar, onay bekleyen içerikler, disiplin kayıtları, anti-spam olayları ve ekip görevlerini tek yerde yönet.</p>'
            . '</div>' . $auditLink . '</header>'
            . ModerationNavigationHtml::render($basePath, 'workspace', $canViewAudit)
            . '<nav class="moderation-stats" aria-label="Moderasyon bölümleri">' . $cards . '</nav>'
            . $create . '<div class="moderation-sections">' . $sections . '</div>' . $script . '</section>';

        return ProfileHtml::page('Moderasyon', $content, $basePath, authenticated: true);
    }

    private static function item(ModerationWorkspaceItem $item, BasePath $basePath, bool $canManage): string
    {
        $summary = $item->summary === null ? '' : '<div class="muted">' . self::e($item->summary) . '</div>';
        $title = self::e($item->title);
        if ($item->actionPath !== null) {
            $title = '<a href="' . self::e($basePath->prepend($item->actionPath)) . '">' . $title . '</a>';
        }
        $actions = '';
        if ($canManage && $item->section === ModerationWorkspaceSection::Tasks) {
            $action = $basePath->prepend('/moderation/tasks/' . rawurlencode($item->sourceId) . '/status');
            $actions = '<form method="post" action="' . self::e($action) . '" data-moderation-form class="moderation-row-action">'
                . '<label><span class="muted">Durum</span><select name="status">'
                . self::option('open', 'Açık', $item->status)
                . self::option('in_progress', 'İşlemde', $item->status)
                . self::option('done', 'Tamamlandı', $item->status)
                . '</select></label><button type="submit">Güncelle</button></form>';
        }

        return '<article class="moderation-row"><div class="moderation-row-main"><span class="moderation-row-type">'
            . self::e($item->sourceType) . '</span><h3>' . $title . '</h3>' . $summary
            . '<div class="moderation-row-meta">Durum · ' . self::e($item->status)
            . ' · Güncelleme · ' . self::e($item->updatedAt->format('Y-m-d H:i')) . ' UTC</div></div>'
            . $actions . '</article>';
    }

    private static function option(string $value, string $label, string $current): string
    {
        return '<option value="' . self::e($value) . '"' . ($value === $current ? ' selected' : '') . '>'
            . self::e($label) . '</option>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
