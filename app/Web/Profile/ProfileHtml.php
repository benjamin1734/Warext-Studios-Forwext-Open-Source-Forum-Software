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
use Forwext\Core\Ui\Responsive\ResponsiveCssCompiler;
use Forwext\Core\Ui\Responsive\ResponsiveRegistry;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\WidgetContext;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use Forwext\Core\Ui\Widget\WidgetRenderService;

final class ProfileHtml
{
    public static function page(
        string $title,
        string $content,
        BasePath $basePath,
        ?NavigationRegistry $navigation = null,
        ?BreadcrumbTrail $breadcrumbs = null,
        bool $authenticated = false,
        ?WidgetRenderService $widgetRenderer = null,
        ?string $viewerId = null,
    ): string {
        $safeTitle = self::escape($title);
        $home = self::escape($basePath->prepend('/'));
        $navigation ??= NavigationRegistry::withCoreDefaults();
        $nav = '';
        foreach ($navigation->visible($authenticated) as $item) {
            $nav .= '<a data-nav-key="' . self::escape($item->key) . '" href="'
                . self::escape($basePath->prepend($item->path)) . '">' . self::escape($item->label) . '</a>';
        }
        $nav .= $authenticated
            ? '<a data-nav-key="auth.logout" href="' . self::escape($basePath->prepend('/logout')) . '">Çıkış</a>'
            : '<a data-nav-key="auth.login" href="' . self::escape($basePath->prepend('/login')) . '">Giriş yap</a>'
                . '<a data-nav-key="auth.register" href="' . self::escape($basePath->prepend('/register')) . '">Kayıt ol</a>';

        $widgetRenderer ??= new WidgetRenderService(WidgetRegistry::withCoreDefaults());
        $widgetContext = new WidgetContext($title, 'tr', $authenticated, $viewerId);
        $pageBefore = $widgetRenderer->renderSlot('page.before', $widgetContext);
        $headerBefore = $widgetRenderer->renderSlot('header.before', $widgetContext);
        $headerAfter = $widgetRenderer->renderSlot('header.after', $widgetContext);
        $mainBefore = $widgetRenderer->renderSlot('main.before', $widgetContext);
        $mainAfter = $widgetRenderer->renderSlot('main.after', $widgetContext);
        $sidebar = $widgetRenderer->renderSlot('sidebar.primary', $widgetContext);
        $footerBefore = $widgetRenderer->renderSlot('footer.before', $widgetContext);
        $footerAfter = $widgetRenderer->renderSlot('footer.after', $widgetContext);
        $pageAfter = $widgetRenderer->renderSlot('page.after', $widgetContext);

        if ($breadcrumbs === null && $title !== 'Ana Sayfa') {
            $breadcrumbs = BreadcrumbTrail::page($title);
        }
        $breadcrumbHtml = self::breadcrumbs($breadcrumbs, $basePath);
        $musicScript = self::escape($basePath->prepend('/assets/profile-music.js'));
        $notificationSoundScript = self::escape($basePath->prepend('/assets/notification-sound.js'));
        $notificationSettingsScript = self::escape($basePath->prepend('/assets/notification-settings.js'));
        $notificationRealtimeScript = self::escape($basePath->prepend('/assets/notification-realtime.js'));
        $presenceScript = self::escape($basePath->prepend('/assets/presence-heartbeat.js'));
        $presenceEndpoint = self::escape($basePath->prepend('/account/presence/heartbeat'));
        $presenceSettingsScript = self::escape($basePath->prepend('/assets/presence-settings.js'));
        $bugReportScript = self::escape($basePath->prepend('/assets/bug-report-link.js'));
        $mobileNavScript = self::escape($basePath->prepend('/assets/mobile-nav.js'));
        $bugReportLink = $authenticated
            ? '<a class="bug-report-fab" data-bug-report-link href="' . self::escape($basePath->prepend('/bugs/report'))
                . '" aria-label="Hata bildir" title="Hata bildir">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                . '<path d="M9 3h6l1 2h3v2h-2.2c.5.9.8 1.9.9 3H21v2h-3.3c-.1.7-.3 1.4-.6 2H21v2h-5.1c-1 1.2-2.3 2-3.9 2s-2.9-.8-3.9-2H3v-2h3.9c-.3-.6-.5-1.3-.6-2H3v-2h3.3c.1-1.1.4-2.1.9-3H5V5h3l1-2Zm3 4a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0v-3a3 3 0 0 0-3-3Z"/>'
                . '</svg></a>'
            : '';

        $pageBeforeHtml = $pageBefore === ''
            ? ''
            : '<div class="ui-page-slot ui-page-slot--before">' . $pageBefore . '</div>';
        $pageAfterHtml = $pageAfter === ''
            ? ''
            : '<div class="ui-page-slot ui-page-slot--after">' . $pageAfter . '</div>';

        $mainHtml = '<main id="main-content" class="wrap">'
            . $mainBefore . $breadcrumbHtml . $content . $mainAfter . '</main>';
        if ($sidebar !== '') {
            $mainHtml = '<div class="layout-shell">' . $mainHtml
                . '<aside class="layout-sidebar" data-forwext-responsive-target="sidebar" aria-label="Kenar çubuğu">'
                . $sidebar . '</aside></div>';
        }

        $footerHtml = '<footer class="site-footer"><div class="footerin">'
            . $footerBefore . $footerAfter . '</div></footer>';

        return '<!doctype html><html lang="tr" dir="ltr"><head><meta charset="utf-8">'
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
            . 'font-weight:850}.brand b{color:var(--accent)}.nav{display:flex;gap:16px;flex-wrap:wrap;justify-content:flex-end}.nav a{color:var(--forwext-component-navigation-muted-text);text-decoration:none;transition:color var(--forwext-component-navigation-motion-duration) ease}.nav a:hover{color:var(--forwext-component-navigation-text)}.nav [data-nav-key="notifications.own"]{display:inline-flex;align-items:center;gap:6px}.nav-notification-badge{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--accent);color:var(--forwext-component-button-contrast-text);font-size:10px;font-weight:900;line-height:1}'
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
            . '.fx-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);text-decoration:none;font-weight:800}.fx-btn:hover{border-color:var(--accent)}.fx-btn--primary{background:var(--accent);color:var(--forwext-component-button-contrast-text);border-color:var(--accent)}'
            . '.forum-home-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:22px;align-items:start}.forum-home-main,.forum-home-side{display:grid;gap:18px}.forum-hero,.forum-view-head,.thread-view-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:24px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(135deg,var(--panel),var(--panel2))}.forum-hero h1,.forum-view-head h1,.thread-view-head h1{margin:4px 0 7px;font-size:28px}.forum-hero p,.forum-view-head p,.thread-view-head p{margin:0;color:var(--muted);max-width:720px}.forum-eyebrow{font-size:11px;letter-spacing:.12em;font-weight:900;color:var(--accent)}.forum-hero-actions,.forum-view-actions,.thread-view-actions{display:flex;gap:8px;flex-wrap:wrap}'
            . '.forum-category{overflow:hidden;border:1px solid var(--line);border-radius:15px;background:var(--panel)}.forum-category-head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:14px 18px;background:var(--panel2);border-bottom:1px solid var(--line)}.forum-category-head h2{margin:0;font-size:17px}.forum-category-head p{margin:3px 0 0;color:var(--muted);font-size:13px}.forum-category-head>span{font-size:12px;color:var(--muted)}.forum-node-list{display:grid}.forum-node{display:grid;grid-template-columns:48px minmax(0,1fr) 130px minmax(190px,250px);gap:14px;align-items:center;padding:16px 18px;border-top:1px solid var(--line)}.forum-node:first-child{border-top:0}.forum-node-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;background:var(--panel2);color:var(--accent);text-decoration:none;font-size:17px}.forum-node-main h3{margin:0;font-size:16px}.forum-node-main h3 a{text-decoration:none}.forum-node-main h3 a:hover{color:var(--accent)}.forum-node-main p{margin:4px 0 0;color:var(--muted);font-size:13px}.forum-node-path{margin-top:5px;color:var(--muted);font-size:11px}.forum-node-counts{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;text-align:center}.forum-node-counts strong,.forum-mini-stats strong{display:block;font-size:17px}.forum-node-counts span,.forum-mini-stats span{display:block;color:var(--muted);font-size:11px}.forum-node-last{min-width:0}.forum-last-title{display:block;text-decoration:none;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.forum-node-last span{display:block;margin-top:3px;color:var(--muted);font-size:11px}.forum-side-card h2{margin:0 0 12px;font-size:16px}.forum-mini-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;text-align:center}.forum-recent-list{display:grid}.forum-recent-list a,.forum-side-card>a{display:flex;justify-content:space-between;gap:8px;padding:10px 0;border-top:1px solid var(--line);text-decoration:none}.forum-recent-list a:first-child,.forum-side-card>a:first-of-type{border-top:0}.forum-recent-list a{display:grid}.forum-recent-list strong{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.forum-recent-list span{font-size:11px;color:var(--muted)}.forum-empty-state{text-align:center;padding:34px}.forum-empty-state .forum-node-icon{margin:0 auto 12px}.forum-empty-state h2,.forum-empty-state h3{margin:0 0 6px}'
            . '.forum-subforums{margin-top:16px}.forum-subforums h2{margin:0 0 12px;font-size:16px}.forum-subforums>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.forum-subforums a{display:grid;gap:3px;padding:12px;border:1px solid var(--line);border-radius:10px;text-decoration:none;background:var(--panel2)}.forum-subforums a span{color:var(--muted);font-size:12px}.forum-thread-panel{margin-top:18px}.forum-thread-panel-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:10px}.forum-thread-panel-head h2{margin:0}.forum-thread-panel-head p{margin:3px 0 0;color:var(--muted);font-size:12px}.forum-thread-list{overflow:hidden;border:1px solid var(--line);border-radius:14px;background:var(--panel)}.forum-thread-row{display:grid;grid-template-columns:34px minmax(0,1fr) 90px 170px;gap:12px;align-items:center;padding:14px 16px;border-top:1px solid var(--line)}.forum-thread-row:first-child{border-top:0}.thread-status-icon{color:var(--accent);font-size:10px;text-align:center}.forum-thread-main h3{margin:2px 0;font-size:15px}.forum-thread-main h3 a{text-decoration:none}.forum-thread-main p{margin:0;color:var(--muted);font-size:11px}.thread-badges{display:flex;gap:5px;flex-wrap:wrap}.thread-badge{display:inline-flex;padding:2px 6px;border:1px solid var(--line);border-radius:999px;font-size:10px;font-weight:800;color:var(--muted)}.thread-badge--accent{border-color:var(--accent);color:var(--accent)}.forum-thread-count{text-align:center}.forum-thread-count strong,.forum-thread-last strong{display:block;font-size:12px}.forum-thread-count span,.forum-thread-last span{display:block;color:var(--muted);font-size:10px}.forum-thread-last{min-width:0}'
            . '.thread-post-list{display:grid;gap:12px}.thread-post{display:grid;grid-template-columns:180px minmax(0,1fr);overflow:hidden;border:1px solid var(--line);border-radius:14px;background:var(--panel)}.thread-post-author{padding:18px;background:var(--panel2);border-right:1px solid var(--line);display:flex;flex-direction:column;align-items:center;text-align:center;gap:7px}.thread-post-avatar{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;background:var(--panel);border:1px solid var(--line);font-size:24px;font-weight:900}.thread-post-author-name{font-weight:850;text-decoration:none}.thread-post-body{min-width:0;padding:16px 18px;display:grid;grid-template-rows:auto 1fr auto;gap:14px}.thread-post-body>header{display:flex;justify-content:space-between;gap:12px;padding-bottom:10px;border-bottom:1px solid var(--line);color:var(--muted);font-size:11px}.thread-post-body>header a{text-decoration:none;color:var(--accent);font-weight:800}.thread-post-content{min-height:90px;overflow-wrap:anywhere}.thread-post-body>footer{padding-top:9px;border-top:1px solid var(--line);font-size:11px}.fx-rich-text{line-height:1.7}.fx-bbcode-quote{margin:12px 0;padding:12px 14px;border-left:3px solid var(--accent);background:var(--panel2);border-radius:0 8px 8px 0}.fx-bbcode-code{overflow:auto;padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}'
            . '.auth-entry{max-width:560px;margin:30px auto}.auth-entry-card{padding:24px;display:grid;gap:18px}.auth-entry-copy h1{margin:4px 0 7px;font-size:28px}.auth-entry-copy p{margin:0;color:var(--muted)}.auth-entry-form{display:grid;gap:14px}.auth-entry-form label{display:grid;gap:7px}.auth-entry-form label>span{font-size:12px;font-weight:800;color:var(--muted)}.auth-entry-form input:not([type=checkbox]),.auth-entry-form select{width:100%;border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:11px 12px;font:inherit}.auth-entry-check{grid-template-columns:auto minmax(0,1fr);align-items:start;gap:10px!important}.auth-entry-check input{margin:3px 0 0}.auth-entry-check span{font-weight:650!important;line-height:1.45}.auth-entry-challenge{min-height:66px;display:flex;align-items:center}.auth-entry-links{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;color:var(--muted);font-size:13px}.auth-entry-links a{color:var(--text);font-weight:800;text-decoration:none}.auth-entry-links a:hover{color:var(--accent)}.auth-entry-submit{justify-content:center;cursor:pointer}.auth-entry-error,.auth-entry-notice{display:grid;gap:4px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}.auth-entry-error{border-color:#8e4545}.auth-entry-notice{border-color:var(--accent)}.auth-entry-notice span{color:var(--muted);font-size:13px}.auth-entry-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.auth-entry-actions button{cursor:pointer}'
            . '.notification-settings{display:grid;gap:16px}.notification-settings-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:22px}.notification-settings-hero h1{margin:4px 0 7px}.notification-settings-hero p{margin:0;color:var(--muted);max-width:720px}.notification-settings-notice{padding:12px 14px;border:1px solid var(--accent);border-radius:10px;background:var(--panel2)}.notification-settings-form{display:grid;gap:18px;padding:20px}.notification-settings-form>label{display:grid;gap:8px}.notification-settings-form>label>span{font-size:13px;font-weight:800}.notification-settings-form select,.notification-settings-form input[type=range]{width:100%}.notification-settings-form select{border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:10px 11px;font:inherit}.notification-settings-switch{grid-template-columns:auto minmax(0,1fr);align-items:start}.notification-settings-switch input{margin-top:4px}.notification-settings-switch span{display:grid;gap:3px}.notification-settings-switch small{font-weight:400;color:var(--muted)}.notification-settings-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.notification-settings-actions button{cursor:pointer}.notification-settings-info h2{margin:0 0 8px;font-size:17px}.notification-settings-info p{margin:6px 0;color:var(--muted)}.notification-hero-side{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.notification-center{display:grid;gap:16px}.notification-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:22px}.notification-hero h1{margin:4px 0 7px}.notification-hero p{margin:0;color:var(--muted);max-width:720px}.notification-unread{min-width:96px;text-align:center;padding:10px 14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}.notification-unread strong{display:block;font-size:25px}.notification-unread span{font-size:11px;color:var(--muted)}.notification-list{display:grid;gap:10px}.notification-item{display:flex;justify-content:space-between;gap:16px;padding:16px 18px;border:1px solid var(--line);border-radius:13px;background:var(--panel)}.notification-item.is-unread{border-color:var(--accent);box-shadow:inset 3px 0 0 var(--accent)}.notification-main{min-width:0}.notification-main h2{margin:5px 0 6px;font-size:16px}.notification-main p{margin:0;color:var(--muted);overflow-wrap:anywhere}.notification-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:11px;color:var(--muted)}.notification-meta>span:first-child{color:var(--accent);font-weight:850}.notification-count{padding:2px 6px;border:1px solid var(--line);border-radius:999px}.notification-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;min-width:185px}.notification-actions form{margin:0}.notification-actions button{cursor:pointer}.notification-read-state{font-size:12px;color:var(--muted)}.notification-empty{display:grid;gap:5px;text-align:center;padding:34px}.notification-empty span{color:var(--muted)}.notification-pagination{display:flex;justify-content:flex-end;gap:8px}'
            . '.forum-compose{display:grid;gap:18px}.forum-compose-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:24px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(135deg,var(--panel),var(--panel2))}.forum-compose-head h1{margin:4px 0 7px;font-size:28px}.forum-compose-head p{margin:0;color:var(--muted);max-width:760px}.forum-compose-form{display:grid;gap:16px;padding:20px}.forum-compose-form label{display:grid;gap:7px}.forum-compose-form label>span{font-size:12px;font-weight:800;color:var(--muted)}.forum-compose-form input,.forum-compose-form textarea{width:100%;border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:11px 12px;font:inherit}.forum-compose-form textarea{resize:vertical;min-height:220px;line-height:1.6}.forum-compose-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.forum-compose-form button.fx-btn{cursor:pointer}.forum-compose-error,.forum-notice{padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}.forum-compose-error{border-color:#8e4545}.forum-notice{margin:14px 0;border-color:var(--accent)}'
            . '.pagination{display:flex;gap:8px;margin-top:18px}.pagination a{padding:7px 11px;border:1px solid var(--line);border-radius:8px;text-decoration:none}.pagination a[aria-current=page]{border-color:var(--accent);color:var(--accent)}'
            . '.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.stat{padding:18px}.stat strong{display:block;font-size:26px}.presence-settings{margin-top:18px;display:flex;gap:12px;align-items:end;flex-wrap:wrap}.presence-settings label{display:grid;gap:6px;min-width:220px}'
            . '.presence-settings input,.presence-settings textarea,.presence-settings select{width:100%;border:var(--forwext-component-input-border-width) solid var(--forwext-component-input-border-color);background:var(--forwext-component-input-background);color:var(--forwext-component-input-text);border-radius:var(--forwext-component-input-radius);padding:10px 11px;font:inherit}.presence-settings textarea{resize:vertical}'
            . '.bug-report-fab{position:fixed;right:20px;bottom:20px;z-index:50;width:46px;height:46px;display:grid;place-items:center;border:var(--forwext-component-button-border-width) solid var(--forwext-component-button-border-color);border-radius:50%;background:var(--forwext-component-button-background);color:var(--muted);text-decoration:none;box-shadow:var(--forwext-semantic-shadow-floating)}.bug-report-fab:hover,.bug-report-fab:focus-visible{color:var(--accent);border-color:var(--accent);outline:none}.bug-report-fab svg{width:22px;height:22px;fill:currentColor}'
            . '.layout-shell{width:min(1320px,calc(100% - 32px));margin:32px auto 64px;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,300px);gap:24px}.layout-shell>.wrap{width:100%;margin:0}.layout-sidebar{min-width:0;align-self:start;display:grid;gap:12px}.site-footer{border-top:var(--forwext-component-footer-border-width) solid var(--forwext-component-footer-border-color);background:var(--forwext-component-footer-background);color:var(--forwext-component-footer-text)}.footerin{width:min(1080px,calc(100% - 32px));margin:auto;padding:20px 0 28px}.core-brand-footer{color:var(--forwext-component-footer-muted-text);font-size:13px;text-align:center}.ui-page-slot{width:min(1080px,calc(100% - 32px));margin-inline:auto}'
            . '@media(max-width:900px){.layout-shell{grid-template-columns:1fr}.layout-sidebar{order:2}.forum-home-layout{grid-template-columns:1fr}.forum-home-side{grid-template-columns:repeat(2,minmax(0,1fr))}.forum-node{grid-template-columns:44px minmax(0,1fr) 110px}.forum-node-last{grid-column:2/-1;padding-top:8px;border-top:1px solid var(--line)}.forum-thread-row{grid-template-columns:28px minmax(0,1fr) 75px}.forum-thread-last{grid-column:2/-1;padding-top:6px;border-top:1px solid var(--line)}}'
            . '@media(max-width:620px){.wrap{margin-top:20px}.search-form,.member-directory-form{grid-template-columns:1fr}.search-wide,.search-actions{grid-column:1}.banner{height:150px}.profilebody{padding:0 16px 20px}.profilehead{align-items:center;margin-top:-34px}'
            . '.profilehead .avatar{width:76px;height:76px}.identity h1{font-size:22px}.topin{min-height:58px;align-items:flex-start;padding:14px 0}.nav{gap:10px}.profilemusic{padding:12px}.profilemusic audio{height:42px}.portfolio-media{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.forum-home-side{grid-template-columns:1fr}.forum-hero,.forum-view-head,.thread-view-head,.forum-compose-head{display:grid;padding:18px}.forum-hero h1,.forum-view-head h1,.thread-view-head h1,.forum-compose-head h1{font-size:23px}.forum-node{grid-template-columns:36px minmax(0,1fr);padding:14px}.forum-node-icon{width:34px;height:34px}.forum-node-counts{grid-column:2;justify-content:start;grid-template-columns:repeat(2,65px);text-align:left}.forum-node-last{grid-column:2}.forum-thread-row{grid-template-columns:24px minmax(0,1fr)}.forum-thread-count,.forum-thread-last{grid-column:2;text-align:left}.forum-thread-count strong,.forum-thread-count span,.forum-thread-last strong,.forum-thread-last span{display:inline;margin-right:5px}.thread-post{grid-template-columns:1fr}.thread-post-author{border-right:0;border-bottom:1px solid var(--line);display:grid;grid-template-columns:52px 1fr;justify-items:start;text-align:left}.thread-post-avatar{grid-row:1/3;width:52px;height:52px;font-size:18px}.thread-post-body{padding:14px}.forum-subforums>div{grid-template-columns:1fr}.notification-hero,.notification-item,.notification-settings-hero{display:grid}.notification-unread{justify-self:start}.notification-actions{justify-content:start;min-width:0}}}'
            . '</style></head><body data-forwext-background-scope="site">'
            . '<a class="skip-link" href="#main-content">İçeriğe geç</a>' . $pageBeforeHtml
            . '<header class="top" data-forwext-background-scope="header">' . $headerBefore
            . '<div class="topin"><a class="brand" href="' . $home . '">Forwext <b>Forum</b></a>'
            . '<button class="nav-toggle" type="button" data-forwext-nav-toggle aria-expanded="false" aria-controls="forwext-primary-navigation"><span aria-hidden="true">☰</span><span>Menü</span></button>'
            . '<nav id="forwext-primary-navigation" class="nav" data-forwext-primary-navigation data-mobile-open="0" aria-label="Ana navigasyon">' . $nav . '</nav></div>'
            . $headerAfter . '</header>' . $mainHtml . $footerHtml . $pageAfterHtml . $bugReportLink
            . '<script src="' . $musicScript . '" defer></script>'
            . '<script src="' . $notificationSoundScript . '" defer></script>'
            . '<script src="' . $notificationSettingsScript . '" defer></script>'
            . '<script src="' . $notificationRealtimeScript . '" defer></script>'
            . '<script src="' . $presenceScript . '" defer></script>'
            . '<script src="' . $presenceSettingsScript . '" defer></script>'
            . '<script src="' . $bugReportScript . '" defer></script>'
            . '<script src="' . $mobileNavScript . '" defer></script></body></html>';
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
                )
                . (new ResponsiveCssCompiler())->compile(ResponsiveRegistry::coreDefaults());
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
