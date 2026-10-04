<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

final class AdminUxQualityHtml
{
    public static function guidance(
        string $purpose,
        string $safeDefault,
        string $preview,
        string $recovery,
    ): string {
        return '<details class="acp-ux-guide" aria-label="Güvenli yönetim rehberi">'
            . '<summary><span><strong>Yönetim rehberi</strong><small>Güvenli varsayılanlar, doğrulama ve geri dönüş notları</small></span>'
            . '<span class="acp-ux-guide-toggle" aria-hidden="true">Ayrıntılar</span></summary>'
            . '<div class="acp-ux-grid">'
            . self::item('Amaç', $purpose)
            . self::item('Güvenli varsayılan', $safeDefault)
            . self::item('Önizleme / doğrulama', $preview)
            . self::item('Geri dönüş', $recovery)
            . '</div></details>';
    }

    private static function item(string $label, string $text): string
    {
        return '<div class="acp-ux-item"><strong>' . self::escape($label) . '</strong><span>'
            . self::escape($text) . '</span></div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
