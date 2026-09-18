<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use Forwext\Core\Ui\Navigation\NavigationRegistry;

final class ProfileHtml
{
    public static function page(
        string $title,
        string $content,
        BasePath $basePath,
        ?NavigationRegistry $navigation = null,
        ?BreadcrumbTrail $breadcrumbs = null,
        bool $authenticated = false,
    ): string {
        $safeTitle = self::escape($title);
        $home = self::escape($basePath->prepend('/'));
        $navigation ??= NavigationRegistry::withCoreDefaults();
        $nav = '';
        foreach ($navigation->visible($authenticated) as $item) {
            $nav .= '<a data-nav-key="' . self::escape($item->key) . '" href="'
                . self::escape($basePath->prepend($item->path)) . '">' . self::escape($item->label) . '</a>';
        }

        if ($breadcrumbs === null && $title !== 'Ana Sayfa') {
            $breadcrumbs = BreadcrumbTrail::page($title);
        }
        $breadcrumbHtml = self::breadcrumbs($breadcrumbs, $basePath);
        $musicScript = self::escape($basePath->prepend('/assets/profile-music.js'));
        $notificationSoundScript = self::escape($basePath->prepend('/assets/notification-sound.js'));
        $notificationRealtimeScript = self::escape($basePath->prepend('/assets/notification-realtime.js'));
        $presenceScript = self::escape($basePath->prepend('/assets/presence-heartbeat.js'));
        $presenceEndpoint = self::escape($basePath->prepend('/account/presence/heartbeat'));
        $presenceSettingsScript = self::escape($basePath->prepend('/assets/presence-settings.js'));
        $bugReportScript = self::escape($basePath->prepend('/assets/bug-report-link.js'));
        $bugReportLink = $authenticated
            ? '<a class="bug-report-fab" data-bug-report-link href="' . self::escape($basePath->prepend('/bugs/report'))
                . '" aria-label="Hata bildir">Hata bildir</a>'
            : '';

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="forwext-presence-endpoint" content="' . $presenceEndpoint . '">'
            . '<title>' . $safeTitle . ' · Forwext</title><style>'
            . ':root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--panel2:#1c2128;--line:#30363d;'
            . '--text:#e6edf3;--muted:#8b949e;--accent:#ff7a1a}*{box-sizing:border-box}body{margin:0;'
            . 'background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}'
            . 'a{color:inherit}.top{border-bottom:1px solid var(--line);background:#10151c}.topin{width:min(1080px,calc(100% - 32px));'
            . 'margin:auto;min-height:64px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{text-decoration:none;font-size:22px;'
            . 'font-weight:850}.brand b{color:var(--accent)}.nav{display:flex;gap:16px;flex-wrap:wrap;justify-content:flex-end}.nav a{color:var(--muted);text-decoration:none}.nav a:hover{color:var(--text)}'
            . '.wrap{width:min(1080px,calc(100% - 32px));margin:32px auto 64px}.breadcrumbs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px;font-size:13px;color:var(--muted)}'
            . '.breadcrumbs a{text-decoration:none}.breadcrumbs a:hover{color:var(--text)}.breadcrumbs .sep{opacity:.55}.card{background:var(--panel);border:1px solid var(--line);'
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
            . '.search-head h1,.search-result-head h2,.member-directory-head h1{margin:0}.search-form,.member-directory-form{margin-top:20px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}'
            . '.search-form label,.member-directory-form label{display:grid;gap:6px}.search-form label span,.member-directory-form label span{font-size:13px;color:var(--muted);font-weight:700}.search-wide{grid-column:1/-1}'
            . '.search-form input,.search-form select,.member-directory-form input,.member-directory-form select,.presence-settings select{width:100%;border:1px solid var(--line);background:#0d1117;color:var(--text);border-radius:9px;padding:10px 11px;font:inherit}'
            . '.search-actions{grid-column:1/-1;display:flex;align-items:center;gap:12px}.search-actions button,.member-directory-form button,.presence-settings button{border:0;border-radius:9px;background:var(--accent);color:#111;padding:10px 18px;font-weight:800;cursor:pointer;align-self:end}'
            . '.search-alert{margin-top:18px;padding:12px 14px;border:1px solid #7d3030;background:#321719;border-radius:10px}.search-results{margin-top:25px}.search-result-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}'
            . '.search-hit{padding:14px 0;border-top:1px solid var(--line)}.search-hit h3{margin:2px 0;font-size:17px}.search-hit h3 a{text-decoration:none}.search-hit-type{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--accent);font-weight:800}.search-hit-id{font-size:12px;overflow-wrap:anywhere}'
            . '.pagination{display:flex;gap:8px;margin-top:18px}.pagination a{padding:7px 11px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.pagination a[aria-current=page]{border-color:var(--accent);color:var(--accent)}'
            . '.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stat{padding:18px}.stat strong{display:block;font-size:26px}.presence-settings{margin-top:18px;display:flex;gap:12px;align-items:end;flex-wrap:wrap}.presence-settings label{display:grid;gap:6px;min-width:220px}'
            . '.presence-settings input,.presence-settings textarea,.presence-settings select{width:100%;border:1px solid var(--line);background:#0d1117;color:var(--text);border-radius:9px;padding:10px 11px;font:inherit}.presence-settings textarea{resize:vertical}'
            . '.bug-report-fab{position:fixed;right:20px;bottom:20px;z-index:50;padding:10px 14px;border-radius:999px;background:var(--accent);color:#111;text-decoration:none;font-weight:800;box-shadow:0 8px 30px #0008}'
            . '@media(max-width:620px){.wrap{margin-top:20px}.search-form,.member-directory-form{grid-template-columns:1fr}.search-wide,.search-actions{grid-column:1}.banner{height:150px}.profilebody{padding:0 16px 20px}.profilehead{align-items:center;margin-top:-34px}'
            . '.profilehead .avatar{width:76px;height:76px}.identity h1{font-size:22px}.topin{min-height:58px;align-items:flex-start;padding:14px 0}.nav{gap:10px}.profilemusic{padding:12px}.profilemusic audio{height:42px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '</style></head><body><header class="top"><div class="topin"><a class="brand" href="' . $home . '">Forwext <b>Forum</b></a>'
            . '<nav class="nav" aria-label="Ana navigasyon">' . $nav . '</nav></div></header>'
            . '<main class="wrap">' . $breadcrumbHtml . $content . '</main>' . $bugReportLink
            . '<script src="' . $musicScript . '" defer></script>'
            . '<script src="' . $notificationSoundScript . '" defer></script>'
            . '<script src="' . $notificationRealtimeScript . '" defer></script>'
            . '<script src="' . $presenceScript . '" defer></script>'
            . '<script src="' . $presenceSettingsScript . '" defer></script>'
            . '<script src="' . $bugReportScript . '" defer></script></body></html>';
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

    private static function breadcrumbs(?BreadcrumbTrail $trail, BasePath $basePath): string
    {
        if ($trail === null || $trail->items === []) {
            return '';
        }
        $parts = [];
        foreach ($trail->items as $index => $item) {
            if ($item->path !== null && $index < count($trail->items) - 1) {
                $parts[] = '<a href="' . self::escape($basePath->prepend($item->path)) . '">'
                    . self::escape($item->label) . '</a>';
            } else {
                $parts[] = '<span' . ($index === count($trail->items) - 1 ? ' aria-current="page"' : '') . '>'
                    . self::escape($item->label) . '</span>';
            }
        }
        return '<nav class="breadcrumbs" aria-label="Breadcrumb">'
            . implode('<span class="sep" aria-hidden="true">/</span>', $parts) . '</nav>';
    }
}
