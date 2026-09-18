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
    ): string {
        $cards = '';
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $cards .= '<div class="card stat"><span class="muted">' . self::e($section->label()) . '</span>'
                . '<strong>' . $snapshot->count($section) . '</strong></div>';
        }

        $sections = '';
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $rows = '';
            foreach ($snapshot->itemsFor($section) as $item) {
                $rows .= self::item($item, $basePath, $canManage);
            }
            if ($rows === '') {
                $rows = '<div class="empty">Kayıt yok.</div>';
            }
            $sections .= '<section class="card section" id="moderation-' . self::e($section->value) . '">'
                . '<h2>' . self::e($section->label()) . ' <span class="muted">(' . $snapshot->count($section) . ')</span></h2>'
                . $rows . '</section>';
        }

        $create = '';
        if ($canManage) {
            $create = '<details class="card section"><summary><strong>Yeni moderasyon görevi</strong></summary>'
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

        $script = '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';
        $content = '<div class="card"><h1 style="margin:0">Moderasyon çalışma alanı</h1>'
            . '<p class="muted">Raporlar, onay bekleyen içerikler, disiplin kayıtları ve ekip görevleri tek dahili görünümde toplanır.</p></div>'
            . '<div class="stats-grid section">' . $cards . '</div>'
            . $create . $sections . $script;

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
            $actions = '<form method="post" action="' . self::e($action) . '" data-moderation-form class="presence-settings">'
                . '<label><span class="muted">Durum</span><select name="status">'
                . self::option('open', 'Açık', $item->status)
                . self::option('in_progress', 'İşlemde', $item->status)
                . self::option('done', 'Tamamlandı', $item->status)
                . '</select></label><button type="submit">Güncelle</button></form>';
        }

        return '<article class="search-hit"><span class="search-hit-type">' . self::e($item->sourceType) . '</span>'
            . '<h3>' . $title . '</h3>' . $summary
            . '<div class="search-hit-id muted">Durum: ' . self::e($item->status)
            . ' · Güncelleme: ' . self::e($item->updatedAt->format('Y-m-d H:i')) . ' UTC</div>'
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
