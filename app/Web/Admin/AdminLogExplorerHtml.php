<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Logs\AdminLogExplorerEntry;
use Forwext\Core\Routing\BasePath;
use JsonException;

final class AdminLogExplorerHtml
{
    /**
     * @param list<AdminLogExplorerEntry> $entries
     * @param array<string,bool> $available
     * @param list<string> $selected
     */
    public static function page(
        array $entries,
        array $available,
        array $selected,
        BasePath $basePath,
        string $query,
        ?string $from,
        ?string $to,
        int $limit,
    ): string {
        $action = self::e($basePath->prepend('/admin/logs'));
        $userChanges = self::e($basePath->prepend('/admin/logs/user-changes'));
        $cron = self::e($basePath->prepend('/admin/system/cron'));
        $sourceControls = '';
        foreach ([
            'system' => 'Sistem logları',
            'audit' => 'Audit olayları',
            'user' => 'Kullanıcı geçmişi',
        ] as $key => $label) {
            if (!($available[$key] ?? false)) {
                continue;
            }
            $sourceControls .= '<label class="log-source-check"><input type="checkbox" name="source_' . $key
                . '" value="1"' . (in_array($key, $selected, true) ? ' checked' : '') . '><span>'
                . self::e($label) . '</span></label>';
        }

        $rows = '';
        foreach ($entries as $entry) {
            $rows .= self::entry($entry);
        }
        if ($rows === '') {
            $rows = '<div class="log-explorer-empty">Filtrelere uyan log kaydı bulunamadı.</div>';
        }

        return '<section class="log-explorer">'
            . '<header class="log-explorer-head"><div><h1>Log Arama</h1>'
            . '<p>Sistem loglarını, audit olaylarını ve izin verilen kullanıcı değişiklik geçmişini tek yerde filtrele.</p></div>'
            . '<nav><a class="acp-button" href="' . $userChanges . '">Kullanıcı değişiklikleri</a>'
            . '<a class="acp-button" href="' . $cron . '">Cron görevleri</a></nav></header>'
            . AdminUxQualityHtml::guidance(
                'Sorun araştırırken farklı first-party log kaynaklarını tek filtreyle karşılaştır.',
                'Kaynak seçmezsen erişebildiğin tüm log kaynakları aranır; en fazla 250 sonuç gösterilir.',
                'Aramayı tarih aralığı ve kaynak türüyle daralt; snapshot ayrıntıları redacted veriden gelir.',
                'Bu ekran salt okunurdur; hiçbir log kaydı buradan değiştirilemez veya silinemez.',
            )
            . '<form class="log-explorer-filter" method="get" action="' . $action . '">'
            . '<input type="hidden" name="filtered" value="1">'
            . '<label class="log-query"><span>Arama</span><input type="search" name="q" maxlength="120" value="'
            . self::e($query) . '" placeholder="Action, target, kullanıcı, mesaj, request id..."></label>'
            . '<label><span>Başlangıç</span><input type="date" name="from" value="' . self::e($from ?? '') . '"></label>'
            . '<label><span>Bitiş</span><input type="date" name="to" value="' . self::e($to ?? '') . '"></label>'
            . '<label><span>Sonuç</span><select name="limit">' . self::limits($limit) . '</select></label>'
            . '<fieldset><legend>Kaynaklar</legend><div>' . $sourceControls . '</div></fieldset>'
            . '<div class="log-filter-actions"><button class="acp-button primary" type="submit">Logları ara</button>'
            . '<a class="acp-button" href="' . $action . '">Sıfırla</a></div></form>'
            . '<section class="log-explorer-panel"><header><h2>Sonuçlar</h2><span>' . count($entries) . ' kayıt</span></header>'
            . '<div class="log-explorer-list">' . $rows . '</div></section></section>';
    }

    private static function entry(AdminLogExplorerEntry $entry): string
    {
        $actor = $entry->actor === null || $entry->actor === '' ? '—' : $entry->actor;
        $target = $entry->target === null || $entry->target === '' ? '—' : $entry->target;
        $context = $entry->context === []
            ? ''
            : '<details class="log-context"><summary>Ayrıntılar</summary><pre>'
                . self::e(self::json($entry->context)) . '</pre></details>';

        return '<article class="log-explorer-row" data-source="' . self::e($entry->source) . '">'
            . '<div class="log-source"><span>' . self::sourceLabel($entry->source) . '</span>'
            . '<time>' . self::e($entry->occurredAt->format('Y-m-d H:i:s')) . ' UTC</time></div>'
            . '<div class="log-main"><strong>' . self::e($entry->title) . '</strong>'
            . '<p>' . self::e($entry->detail) . '</p>'
            . '<small>Actor · ' . self::e($actor) . ' · Target · ' . self::e($target) . '</small>'
            . $context . '</div></article>';
    }

    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            'system' => 'SYSTEM',
            'audit' => 'AUDIT',
            'user' => 'USER',
            default => strtoupper($source),
        };
    }

    private static function limits(int $selected): string
    {
        $html = '';
        foreach ([50, 100, 250] as $value) {
            $html .= '<option value="' . $value . '"' . ($value === $selected ? ' selected' : '') . '>'
                . $value . '</option>';
        }

        return $html;
    }

    /** @param array<string,mixed> $context */
    private static function json(array $context): string
    {
        try {
            return json_encode(
                $context,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return '{"error":"context-unavailable"}';
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
