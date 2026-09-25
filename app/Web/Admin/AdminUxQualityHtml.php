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
        return '<aside class="acp-ux-guide" aria-label="Güvenli yönetim rehberi">'
            . '<div><strong>Bu ekranda güvenli çalışma</strong><p>Kompleks ayarları önce bul, doğrula, sonra uygula.</p></div>'
            . '<div class="acp-ux-grid">'
            . self::item('Amaç', $purpose)
            . self::item('Güvenli varsayılan', $safeDefault)
            . self::item('Önizleme / doğrulama', $preview)
            . self::item('Geri dönüş', $recovery)
            . '</div></aside>';
    }

    public static function css(): string
    {
        return '.acp-ux-guide{display:grid;gap:10px;border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:16px}'
            . '.acp-ux-guide>div:first-child p{margin:4px 0 0;color:var(--muted)}'
            . '.acp-ux-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px}'
            . '.acp-ux-item{border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--panel2)}'
            . '.acp-ux-item strong{display:block;margin-bottom:4px}.acp-ux-item span{color:var(--muted);font-size:.9rem;line-height:1.45}';
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
