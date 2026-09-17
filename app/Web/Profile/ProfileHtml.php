<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Routing\BasePath;

final class ProfileHtml
{
    public static function page(string $title, string $content, BasePath $basePath): string
    {
        $safeTitle = self::escape($title);
        $home = self::escape($basePath->prepend('/'));
        $members = self::escape($basePath->prepend('/members'));
        $musicScript = self::escape($basePath->prepend('/assets/profile-music.js'));
        $notificationSoundScript = self::escape($basePath->prepend('/assets/notification-sound.js'));
        $notificationRealtimeScript = self::escape($basePath->prepend('/assets/notification-realtime.js'));

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $safeTitle . ' · Forwext</title><style>'
            . ':root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--panel2:#1c2128;--line:#30363d;'
            . '--text:#e6edf3;--muted:#8b949e;--accent:#ff7a1a}*{box-sizing:border-box}body{margin:0;'
            . 'background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}'
            . 'a{color:inherit}.top{border-bottom:1px solid var(--line);background:#10151c}.topin{width:min(1080px,calc(100% - 32px));'
            . 'margin:auto;height:64px;display:flex;align-items:center;justify-content:space-between}.brand{text-decoration:none;font-size:22px;'
            . 'font-weight:850}.brand b{color:var(--accent)}.nav{display:flex;gap:16px}.nav a{color:var(--muted);text-decoration:none}.nav a:hover{color:var(--text)}'
            . '.wrap{width:min(1080px,calc(100% - 32px));margin:32px auto 64px}.card{background:var(--panel);border:1px solid var(--line);'
            . 'border-radius:14px;padding:20px}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}'
            . '.member{display:flex;gap:13px;align-items:center;text-decoration:none}.avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;'
            . 'background:var(--panel2);border:1px solid var(--line);display:grid;place-items:center;font-weight:800;font-size:20px}.profile{overflow:hidden;border:1px solid var(--line);'
            . 'border-radius:16px;background:var(--panel)}.banner{width:100%;height:220px;object-fit:cover;background:linear-gradient(135deg,#20262e,#141920);display:block}'
            . '.profilebody{padding:0 24px 26px}.profilehead{display:flex;gap:16px;align-items:end;margin-top:-46px}.profilehead .avatar{width:96px;height:96px;'
            . 'font-size:30px;border:4px solid var(--panel)}.identity{padding-bottom:8px}.identity h1{margin:0;font-size:27px}.tabs{display:flex;gap:8px;flex-wrap:wrap;'
            . 'margin:22px 0}.tabs a{padding:8px 12px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--muted)}'
            . '.section{padding-top:8px;margin-top:18px}.section h2{font-size:17px;margin:0 0 10px}.about{white-space:pre-wrap;overflow-wrap:anywhere}.social{display:flex;gap:9px;flex-wrap:wrap}'
            . '.social a{padding:7px 10px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.empty{padding:28px;text-align:center;color:var(--muted)}'
            . '.profilemusic{margin:20px 0 4px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}'
            . '.profilemusic-title{font-weight:700;margin-bottom:9px;overflow-wrap:anywhere}.profilemusic audio{display:block;width:100%;height:40px;max-width:680px}'
            . '@media(max-width:620px){.wrap{margin-top:20px}.banner{height:150px}.profilebody{padding:0 16px 20px}.profilehead{align-items:center;margin-top:-34px}'
            . '.profilehead .avatar{width:76px;height:76px}.identity h1{font-size:22px}.topin{height:58px}.nav{gap:10px}.profilemusic{padding:12px}.profilemusic audio{height:42px}}'
            . '</style></head><body><header class="top"><div class="topin"><a class="brand" href="' . $home . '">Forwext <b>Forum</b></a>'
            . '<nav class="nav" aria-label="Ana navigasyon"><a href="' . $members . '">Üyeler</a></nav></div></header>'
            . '<main class="wrap">' . $content . '</main>'
            . '<script src="' . $musicScript . '" defer></script>'
            . '<script src="' . $notificationSoundScript . '" defer></script>'
            . '<script src="' . $notificationRealtimeScript . '" defer></script></body></html>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function memberPath(BasePath $basePath, string $username): string
    {
        return $basePath->prepend('/members/' . rawurlencode($username));
    }

    public static function initial(string $username): string
    {
        if (preg_match('/^./us', $username, $matches) === 1) {
            return self::escape($matches[0]);
        }

        return '?';
    }
}
