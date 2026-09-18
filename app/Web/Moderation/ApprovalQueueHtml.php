<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Moderation\Approval\ApprovalQueueItem;
use Forwext\Core\Moderation\Approval\ApprovalQueueSnapshot;
use Forwext\Core\Routing\BasePath;

final class ApprovalQueueHtml
{
    public static function page(ApprovalQueueSnapshot $snapshot, BasePath $basePath, bool $canManage): string
    {
        $rows = '';
        foreach ($snapshot->items as $item) {
            $rows .= self::item($item, $canManage);
        }
        if ($rows === '') {
            $rows = '<div class="empty">Onay bekleyen içerik yok.</div>';
        }

        $formOpen = $canManage
            ? '<form method="post" action="' . self::e($basePath->prepend('/moderation/approval/actions')) . '" data-moderation-form>'
            : '';
        $formClose = $canManage ? '</form>' : '';
        $actions = '';
        if ($canManage) {
            $actions = '<div class="card section"><div class="search-form">'
                . '<label><span>Toplu işlem</span><select name="action" required>'
                . '<option value="approve">Onayla</option><option value="reject">Reddet</option></select></label>'
                . '<label><span>Neden</span><select name="reason" required>'
                . '<option value="approval.manual">Manuel inceleme</option>'
                . '<option value="approval.rules">Kural değerlendirmesi</option>'
                . '<option value="approval.spam">Spam/istenmeyen içerik</option>'
                . '<option value="approval.other">Diğer</option></select></label>'
                . '<div class="search-actions"><button type="submit">Seçilenlere uygula</button></div>'
                . '</div></div>';
        }

        $content = '<div class="card"><h1 style="margin:0">Onay kuyruğu</h1>'
            . '<p class="muted">Yetkili olduğunuz içerik türlerindeki bekleyen kayıtlar tek kuyrukta gösterilir. '
            . 'Görünen içerikler backend permission kontrollerinden geçer.</p>'
            . '<p class="muted">Toplam bekleyen: ' . $snapshot->total . '</p></div>'
            . $formOpen . $actions . '<section class="card section">' . $rows . '</section>' . $formClose
            . '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script>';

        return ProfileHtml::page('Onay kuyruğu', $content, $basePath, authenticated: true);
    }

    private static function item(ApprovalQueueItem $item, bool $canManage): string
    {
        $check = $canManage
            ? '<label class="muted"><input type="checkbox" name="items[]" value="'
                . self::e($item->selection()->token()) . '"> Seç</label>'
            : '';
        $summary = $item->summary === null ? '' : '<div class="muted">' . self::e($item->summary) . '</div>';
        return '<article class="search-hit"><span class="search-hit-type">' . self::e($item->sourceType) . '</span>'
            . '<h3>' . self::e($item->title) . '</h3>' . $summary
            . '<div class="search-hit-id muted">Güncelleme: ' . self::e($item->updatedAt->format('Y-m-d H:i')) . ' UTC</div>'
            . $check . '</article>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
