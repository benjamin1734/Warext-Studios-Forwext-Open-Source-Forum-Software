<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Logs\AdminUserChangeEntry;
use Forwext\Core\Routing\BasePath;

final class AdminUserChangeLogHtml
{
    /** @param list<AdminUserChangeEntry> $entries */
    public static function page(
        array $entries,
        BasePath $basePath,
        string $query,
        ?string $from,
        ?string $to,
        int $limit,
    ): string {
        $action = self::e($basePath->prepend('/admin/logs/user-changes'));
        $allLogs = self::e($basePath->prepend('/admin/logs'));
        $rows = '';
        foreach ($entries as $entry) {
            $transition = $entry->fromStatus !== null && $entry->toStatus !== null
                ? self::e($entry->fromStatus) . ' → ' . self::e($entry->toStatus)
                : '—';
            $actor = $entry->actorUsername ?? $entry->actorUserId ?? 'Sistem';
            $rows .= '<tr><td><strong>' . self::e($entry->username) . '</strong><small>'
                . self::e($entry->userId) . '</small></td><td>' . self::e($entry->eventType) . '</td><td>'
                . self::e(implode(', ', $entry->changedFields)) . '</td><td>' . $transition . '</td><td>'
                . self::e($actor) . '</td><td>' . self::e($entry->reasonCode ?? '—') . '</td><td>'
                . self::e($entry->occurredAt->format('Y-m-d H:i:s')) . ' UTC</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="ops-muted">Kullanıcı değişiklik kaydı bulunamadı.</td></tr>';
        }

        return '<section class="user-change-log">'
            . '<header class="log-explorer-head"><div><h1>Kullanıcı Değişiklik Logu</h1>'
            . '<p>Hesap alanı, durum ve kullanıcı geçmişi değişikliklerini global olarak incele.</p></div>'
            . '<a class="acp-button" href="' . $allLogs . '">Tüm logları ara</a></header>'
            . '<form class="log-explorer-filter user-change-filter" method="get" action="' . $action . '">'
            . '<label class="log-query"><span>Kullanıcı / olay / alan</span><input type="search" name="q" maxlength="120" value="'
            . self::e($query) . '" placeholder="Kullanıcı adı, event type, changed field..."></label>'
            . '<label><span>Başlangıç</span><input type="date" name="from" value="' . self::e($from ?? '') . '"></label>'
            . '<label><span>Bitiş</span><input type="date" name="to" value="' . self::e($to ?? '') . '"></label>'
            . '<label><span>Sonuç</span><select name="limit">' . self::limits($limit) . '</select></label>'
            . '<div class="log-filter-actions"><button class="acp-button primary" type="submit">Göster</button>'
            . '<a class="acp-button" href="' . $action . '">Sıfırla</a></div></form>'
            . '<section class="log-table-panel"><header><h2>Değişiklikler</h2><span>' . count($entries) . ' kayıt</span></header>'
            . '<div class="ops-table-wrap"><table class="ops-table user-change-table"><thead><tr>'
            . '<th>Kullanıcı</th><th>Olay</th><th>Değişen alanlar</th><th>Durum değişimi</th>'
            . '<th>Aktör</th><th>Neden</th><th>Zaman</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '</section></section>';
    }

    private static function limits(int $selected): string
    {
        $html = '';
        foreach ([50, 100, 250] as $value) {
            $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>'
                . $value . '</option>';
        }

        return $html;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
