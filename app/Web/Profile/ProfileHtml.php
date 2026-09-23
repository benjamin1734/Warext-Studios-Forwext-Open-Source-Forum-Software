<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenCssCompiler;
use Forwext\Core\Ui\Appearance\ComponentAppearanceCssCompiler;
use Forwext\Core\Ui\Appearance\Background\BackgroundCssCompiler;
use Forwext\Core\Ui\Appearance\Background\BackgroundRegistry;
use Forwext\Core\Ui\Appearance\ComponentAppearanceRegistry;
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
                . '" aria-label="Hata bildir" title="Hata bildir">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                . '<path d="M9 3h6l1 2h3v2h-2.2c.5.9.8 1.9.9 3H21v2h-3.3c-.1.7-.3 1.4-.6 2H21v2h-5.1c-1 1.2-2.3 2-3.9 2s-2.9-.8-3.9-2H3v-2h3.9c-.3-.6-.5-1.3-.6-2H3v-2h3.3c.1-1.1.4-2.1.9-3H5V5h3l1-2Zm3 4a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0v-3a3 3 0 0 0-3-3Z"/>'
                . '</svg></a>'
            : '';

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="forwext-presence-endpoint" content="' . $presenceEndpoint . '">'
            . '<title>' . $safeTitle . ' · Forwext</title><style>' . self::appearanceCss($basePath)
            . ':root{color-scheme:dark;--bg:var(--forwext-semantic-page-background);'
            . '--panel:var(--forwext-semantic-surface-primary);'
            . '--panel2:var(--forwext-semantic-surface-secondary);'
            . '--line:var(--forwext-semantic-border-default);'
            . '--text:var(--forwext-semantic-text-primary);'
            . '--muted:var(--forwext-semantic-text-muted);'
            . '--accent:var(--forwext-semantic-accent-primary)}*{box-sizing:border-box}body{margin:0;'
            . 'background:var(--bg);color:var(--text);'
            . 'font:var(--forwext-typography-font-size-base)/var(--forwext-typography-line-height-body) '
            . 'var(--forwext-typography-font-family-sans)}'
            . 'a{color:inherit}.top{border-bottom:var(--forwext-component-header-border-width) solid var(--forwext-component-header-border-color);'
            . 'background:var(--forwext-component-header-background)}.topin{width:min(1080px,calc(100% - 32px));'
            . 'margin:auto;min-height:64px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{text-decoration:none;font-size:22px;'
            . 'font-weight:850}.brand b{color:var(--accent)}.nav{display:flex;gap:16px;flex-wrap:wrap;justify-content:flex-end}.nav a{color:var(--forwext-component-navigation-muted-text);text-decoration:none;transition:color var(--forwext-component-navigation-motion-duration) ease}.nav a:hover{color:var(--forwext-component-navigation-text)}'
            . '.wrap{width:min(1080px,calc(100% - 32px));margin:32px auto 64px}.breadcrumbs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px;font-size:13px;color:var(--muted)}'
            . '.breadcrumbs a{text-decoration:none}.breadcrumbs a:hover{color:var(--text)}.breadcrumbs .sep{opacity:.55}.card{background:var(--forwext-component-forum-background);border:var(--forwext-component-forum-border-width) solid var(--forwext-component-forum-border-color);'
            . 'border-radius:var(--forwext-component-forum-radius);padding:var(--forwext-component-forum-spacing)}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}'
            . '.member{display:flex;gap:13px;align-items:center;text-decoration:none}.avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;'
            . 'background:var(--panel2);border:1px solid var(--line);display:grid;place-items:center;font-weight:800;font-size:20px}.profile{overflow:hidden;border:var(--forwext-component-profile-border-width) solid var(--forwext-component-profile-border-color);'
            . 'border-radius:var(--forwext-component-profile-radius);background:var(--forwext-component-profile-background)}.banner{width:100%;height:220px;object-fit:cover;background:linear-gradient(135deg,#20262e,#141920);display:block}'
            . '.profilebody{padding:0 24px 26px}.profilehead{display:flex;gap:16px;align-items:end;margin-top:-46px}.profilehead .avatar{width:96px;height:96px;'
            . 'font-size:30px;border:4px solid var(--panel)}.identity{padding-bottom:8px}.identity h1{margin:0;font-size:27px}.tabs{display:flex;gap:8px;flex-wrap:wrap;'
            . 'margin:22px 0}.tabs a{padding:8px 12px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--muted)}'
            . '.section{padding-top:8px;margin-top:18px}.section h2{font-size:17px;margin:0 0 10px}.about{white-space:pre-wrap;overflow-wrap:anywhere}.social{display:flex;gap:9px;flex-wrap:wrap}'
            . '.social a{padding:7px 10px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.empty{padding:28px;text-align:center;color:var(--muted)}'
            . '.profilemusic{margin:20px 0 4px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}'
            . '.profilemusic-title{font-weight:700;margin-bottom:9px;overflow-wrap:anywhere}.profilemusic audio{display:block;width:100%;height:40px;max-width:680px}'
            . '.search-head h1,.search-result-head h2,.member-directory-head h1{margin:0}.search-form,.member-directory-form{margin-top:20px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}'
            . '.search-form label,.member-directory-form label{display:grid;gap:6px}.search-form label span,.member-directory-form label span{font-size:13px;color:var(--muted);font-weight:700}.search-wide{grid-column:1/-1}'
            . '.search-form input,.search-form select,.search-form textarea,.member-directory-form input,.member-directory-form select,.presence-settings select{width:100%;border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:10px 11px;font:inherit}.search-form textarea{resize:vertical}'
            . '.search-actions{grid-column:1/-1;display:flex;align-items:center;gap:12px}.search-actions button,.member-directory-form button,.presence-settings button{border:0;border-radius:var(--forwext-component-button-radius);background:var(--forwext-component-button-accent);color:var(--forwext-component-button-contrast-text);padding:10px 18px;font-weight:800;cursor:pointer;align-self:end}'
            . '.search-alert{margin-top:18px;padding:12px 14px;border:var(--forwext-component-alert-border-width) solid var(--forwext-component-alert-border-color);background:var(--forwext-component-alert-background);border-radius:var(--forwext-component-alert-radius)}.search-results{margin-top:25px}.search-result-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}'
            . '.search-hit{padding:14px 0;border-top:1px solid var(--line)}.search-hit h3{margin:2px 0;font-size:17px}.search-hit h3 a{text-decoration:none}.search-hit-type{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--accent);font-weight:800}.search-hit-id{font-size:12px;overflow-wrap:anywhere}'
            . '.portfolio-media{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:18px 0}.portfolio-media figure{margin:0;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--panel2);aspect-ratio:16/10}.portfolio-media img,.search-hit>img{display:block;width:100%;height:100%;object-fit:cover}.search-hit>img{max-height:280px;border-radius:10px;margin-bottom:12px}'            . '.trophy-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}.trophy-card{overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}'
            . '.trophy-banner{display:block;width:100%;height:92px;object-fit:cover}.trophy-card-body{display:flex;gap:12px;padding:14px}.trophy-icon{width:52px;height:52px;object-fit:contain;border-radius:10px;flex:0 0 auto}.trophy-card h3{margin:2px 0 5px}.trophy-card p{margin:5px 0}.trophy-history{margin:0;padding-left:20px;color:var(--muted)}'            . '.market-head{display:grid;gap:14px}.market-head h1{margin:0}.market-manage-link{justify-self:start;padding:8px 12px;border:1px solid var(--line);border-radius:9px;text-decoration:none}.market-result-head,.market-detail-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.market-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}.market-list{display:grid;gap:10px}.market-card{overflow:hidden;border:1px solid var(--line);border-radius:14px;background:var(--panel)}.market-card>div{padding:16px}.market-cover{display:block;background:var(--panel2)}.market-cover img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover}.market-card h3{margin:5px 0}.market-card h3 a{text-decoration:none}.market-price{font-size:19px;font-weight:850;color:#fff}.market-badge{display:inline-block;margin:0 6px 7px 0;padding:3px 7px;border-radius:var(--forwext-component-badge-radius);background:var(--forwext-component-badge-background);border:var(--forwext-component-badge-border-width) solid var(--forwext-component-badge-border-color);color:var(--forwext-component-badge-text);font-size:11px;font-weight:800}.market-tags{display:flex;gap:8px;flex-wrap:wrap}.market-tags a{padding:4px 8px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--muted)}.market-specs{display:grid;grid-template-columns:minmax(120px,220px) 1fr;gap:7px 14px}.market-specs dt{font-weight:750}.market-specs dd{margin:0;color:var(--muted)}.market-review{padding:14px 0;border-top:1px solid var(--line)}.market-review>div{display:flex;justify-content:space-between;gap:12px}.market-stars{color:#ffb36f}.market-actions{display:flex;gap:8px;flex-wrap:wrap}.market-actions form{margin:0}.market-actions button{border:var(--forwext-component-button-border-width) solid var(--forwext-component-button-border-color);border-radius:var(--forwext-component-button-radius);background:var(--forwext-component-button-background);color:var(--forwext-component-button-text);padding:8px 11px;cursor:pointer}.market-category-links{display:flex;gap:8px;flex-wrap:wrap}.market-category-links a{padding:8px 11px;border:1px solid var(--line);border-radius:9px;text-decoration:none}.market-success{border-color:#2f6f47;background:#173722}'            . '.market-media{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:18px 0}.market-media figure{margin:0;overflow:hidden;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}.market-media img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover}.market-media figure form{padding:8px}.market-media figure button{border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:7px;padding:6px 9px;cursor:pointer}'
            . '.pagination{display:flex;gap:8px;margin-top:18px}.pagination a{padding:7px 11px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.pagination a[aria-current=page]{border-color:var(--accent);color:var(--accent)}'
            . '.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stat{padding:18px}.stat strong{display:block;font-size:26px}.presence-settings{margin-top:18px;display:flex;gap:12px;align-items:end;flex-wrap:wrap}.presence-settings label{display:grid;gap:6px;min-width:220px}'
            . '.presence-settings input,.presence-settings textarea,.presence-settings select{width:100%;border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:10px 11px;font:inherit}.presence-settings textarea{resize:vertical}'
            . '.bug-report-fab{position:fixed;right:20px;bottom:20px;z-index:50;width:46px;height:46px;display:grid;place-items:center;border:var(--forwext-component-button-border-width) solid var(--forwext-component-button-border-color);border-radius:50%;background:var(--forwext-component-button-background);color:var(--muted);text-decoration:none;box-shadow:var(--forwext-semantic-shadow-floating)}.bug-report-fab:hover,.bug-report-fab:focus-visible{color:var(--accent);border-color:var(--accent);outline:none}.bug-report-fab svg{width:22px;height:22px;fill:currentColor}'
            . '@media(max-width:620px){.wrap{margin-top:20px}.search-form,.member-directory-form{grid-template-columns:1fr}.search-wide,.search-actions{grid-column:1}.banner{height:150px}.profilebody{padding:0 16px 20px}.profilehead{align-items:center;margin-top:-34px}'
            . '.profilehead .avatar{width:76px;height:76px}.identity h1{font-size:22px}.topin{min-height:58px;align-items:flex-start;padding:14px 0}.nav{gap:10px}.profilemusic{padding:12px}.profilemusic audio{height:42px}.portfolio-media{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '</style></head><body data-forwext-background-scope="site"><header class="top" data-forwext-background-scope="header"><div class="topin"><a class="brand" href="' . $home . '">Forwext <b>Forum</b></a>'
            . '<nav class="nav" aria-label="Ana navigasyon">' . $nav . '</nav></div></header>'
            . '<main class="wrap">' . $breadcrumbHtml . $content . '</main>' . $bugReportLink
            . '<script src="' . $musicScript . '" defer></script>'
            . '<script src="' . $notificationSoundScript . '" defer></script>'
            . '<script src="' . $notificationRealtimeScript . '" defer></script>'
            . '<script src="' . $presenceScript . '" defer></script>'
            . '<script src="' . $presenceSettingsScript . '" defer></script>'
            . '<script src="' . $bugReportScript . '" defer></script></body></html>';
    }

    private static function appearanceCss(BasePath $basePath): string
    {
        static $cache = [];

        $cacheKey = $basePath->prepend('/');
        if (!isset($cache[$cacheKey])) {
            $catalog = DesignTokenCatalog::coreDefaults();
            $cache[$cacheKey] = (new DesignTokenCssCompiler())->compile($catalog)
                . (new ComponentAppearanceCssCompiler())->compile(
                    ComponentAppearanceRegistry::coreDefaults($catalog),
                    $catalog,
                )
                . (new BackgroundCssCompiler())->compile(
                    BackgroundRegistry::coreDefaults($catalog),
                    $catalog,
                    $basePath,
                );
        }

        return $cache[$cacheKey];
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
