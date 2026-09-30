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
            $rows = '<div class="surface-empty"><strong>Onay bekleyen içerik yok.</strong><span>Kuyruk şu anda temiz.</span></div>';
        }

        $formOpen = $canManage
            ? '<form method="post" action="' . self::e($basePath->prepend('/moderation/approval/actions')) . '" data-moderation-form>'
            : '';
        $formClose = $canManage ? '</form>' : '';
        $actions = '';
        if ($canManage) {
            $actions = '<section class="surface-panel moderation-bulk"><div class="search-form">'
                . '<label><span>Toplu işlem</span><select name="action" required>'
                . '<option value="approve">Onayla</option><option value="reject">Reddet</option></select></label>'
                . '<label><span>Neden</span><select name="reason" required>'
                . '<option value="approval.manual">Manuel inceleme</option>'
                . '<option value="approval.rules">Kural değerlendirmesi</option>'
                . '<option value="approval.spam">Spam/istenmeyen içerik</option>'
                . '<option value="approval.other">Diğer</option></select></label>'
                . '<div class="search-actions"><button type="submit">Seçilenlere uygula</button></div>'
                . '</div></section>';
        }

        $content = '<section class="moderation-subpage discovery-page"><header class="surface-head moderation-subpage-head"><div>'
            . '<span class="forum-eyebrow">MODERASYON</span><h1>Onay kuyruğu</h1>'
            . '<p>Yetkili olduğun içerik türlerindeki bekleyen kayıtları tek kuyrukta incele.</p></div>'
            . '<span class="moderation-head-count">' . $snapshot->total . ' bekleyen</span></header>'
            . $formOpen . $actions . '<section class="surface-panel moderation-list-panel"><div class="moderation-list">'
            . $rows . '</div></section>' . $formClose
            . '<script src="' . self::e($basePath->prepend('/assets/moderation-workspace.js')) . '" defer></script></section>';

        return ProfileHtml::page('Onay kuyruğu', $content, $basePath, authenticated: true);
    }

    private static function item(ApprovalQueueItem $item, bool $canManage): string
    {
        $check = $canManage
            ? '<label class="muted"><input type="checkbox" name="items[]" value="'
                . self::e($item->selection()->token()) . '"> Seç</label>'
            : '';
        $summary = $item->summary === null ? '' : '<div class="muted">' . self::e($item->summary) . '</div>';
        return '<article class="moderation-list-row"><div class="moderation-list-row-main"><span class="moderation-row-type">'
            . self::e($item->sourceType) . '</span><h3>' . self::e($item->title) . '</h3>' . $summary
            . '<div class="moderation-row-meta">Güncelleme · ' . self::e($item->updatedAt->format('Y-m-d H:i')) . ' UTC</div></div>'
            . $check . '</article>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
