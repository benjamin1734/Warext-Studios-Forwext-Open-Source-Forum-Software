<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Error;

final class ErrorPage
{
    public static function render(string $reference, string $homePath = '/'): string
    {
        $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeHome = htmlspecialchars($homePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Forwext · İstek tamamlanamadı</title>'
            . '<style>:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'padding:24px;background:#0c1117;color:#eef2f7;font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}'
            . '.error-card{width:min(560px,100%);padding:26px;border:1px solid #2b3440;border-radius:14px;background:#151b23;'
            . 'box-shadow:0 24px 70px rgba(0,0,0,.28)}.brand{display:flex;align-items:center;gap:10px;margin-bottom:24px;'
            . 'font-weight:850}.mark{width:30px;height:30px;display:grid;place-items:center;border-radius:8px;background:#ff7a1a;'
            . 'color:#111;font-weight:950}.eyebrow{color:#ff8a33;font-size:11px;font-weight:850;letter-spacing:.08em}'
            . 'h1{margin:4px 0 8px;font-size:25px;line-height:1.2}p{margin:0;color:#aab4c0}.reference{margin-top:18px;'
            . 'padding:10px 12px;border:1px solid #2b3440;border-radius:8px;background:#10151c;color:#c8d0da;font:12px/1.5 ui-monospace,monospace;'
            . 'overflow-wrap:anywhere}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.actions a{min-height:38px;display:inline-flex;'
            . 'align-items:center;padding:8px 12px;border:1px solid #384453;border-radius:8px;color:#eef2f7;text-decoration:none;font-weight:750}'
            . '.actions a:first-child{border-color:#ff7a1a;background:#ff7a1a;color:#16100b}@media(max-width:520px){.error-card{padding:20px}}</style>'
            . '</head><body><main class="error-card" role="alert"><div class="brand"><span class="mark" aria-hidden="true">F</span>'
            . '<span>Forwext</span></div><span class="eyebrow">SUNUCU HATASI</span><h1>İstek tamamlanamadı</h1>'
            . '<p>Bu işlem sırasında beklenmeyen bir hata oluştu. Tekrar deneyebilir veya ana sayfaya dönebilirsin.</p>'
            . '<div class="reference"><strong>Referans:</strong> ' . $safeReference . '</div>'
            . '<div class="actions"><a href="' . $safeHome . '">Ana sayfaya dön</a>'
            . '<a href="">Tekrar dene</a></div></main></body></html>';
    }
}
