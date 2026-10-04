<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Forum\State\WatchNotificationMode;
use Forwext\Core\Routing\BasePath;

final class WatchedContentHtml
{
    /**
     * @param list<array{
     *   title:string,
     *   subtitle:string,
     *   href:string,
     *   mode:WatchNotificationMode,
     *   updated_at:DateTimeImmutable
     * }> $rows
     */
    public static function page(
        array $rows,
        bool $threadList,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $title = $threadList ? 'Takip edilen konular' : 'Takip edilen forumlar';
        $description = $threadList
            ? 'Takip ettiğin ve hâlâ erişebildiğin konular.'
            : 'Takip ettiğin ve hâlâ erişebildiğin forumlar.';

        $tabs = '<nav class="tabs surface-tabs watched-tabs" aria-label="Takip edilen içerikler">'
            . '<a' . ($threadList ? ' aria-current="page"' : '') . ' href="'
            . self::e($basePath->prepend('/account/watched/threads')) . '">Konular</a>'
            . '<a' . (!$threadList ? ' aria-current="page"' : '') . ' href="'
            . self::e($basePath->prepend('/account/watched/forums')) . '">Forumlar</a></nav>';

        $items = '';
        foreach ($rows as $row) {
            $updated = $row['updated_at']->setTimezone($timezone);
            $items .= '<article class="watched-row"><div class="watched-row-main">'
                . '<h2><a href="' . self::e($row['href']) . '">' . self::e($row['title']) . '</a></h2>'
                . ($row['subtitle'] === '' ? '' : '<p>' . self::e($row['subtitle']) . '</p>')
                . '</div><div class="watched-row-meta"><span>' . self::e(self::modeLabel($row['mode'])) . '</span>'
                . '<time datetime="' . self::e($row['updated_at']->format(DATE_ATOM)) . '">'
                . self::e($updated->format('d.m.Y H:i')) . '</time></div></article>';
        }

        if ($items === '') {
            $items = '<div class="surface-empty"><strong>Takip edilen içerik yok.</strong>'
                . '<span>Bir forumu veya konuyu takip ettiğinde burada görünecek.</span></div>';
        }

        $content = '<section class="watched-content discovery-page"><header class="surface-head watched-head">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>' . self::e($title) . '</h1><p>'
            . self::e($description) . '</p></div></header>'
            . $tabs
            . '<section class="surface-panel watched-panel"><div class="watched-list">' . $items
            . '</div></section></section>';

        return ProfileHtml::page($title, $content, $basePath, authenticated: true);
    }

    public static function authenticationRequired(BasePath $basePath): string
    {
        return ProfileHtml::page(
            'Takip edilen içerikler',
            '<section class="watched-content discovery-page"><div class="surface-panel surface-empty">'
            . '<h1>Oturum gerekli</h1><p>Takip ettiğin içerikleri görmek için giriş yapmalısın.</p>'
            . '<a class="fx-btn fx-btn--primary" href="' . self::e($basePath->prepend('/login')) . '">Giriş yap</a>'
            . '</div></section>',
            $basePath,
        );
    }

    private static function modeLabel(WatchNotificationMode $mode): string
    {
        return match ($mode) {
            WatchNotificationMode::None => 'Bildirim yok',
            WatchNotificationMode::InApp => 'Uygulama içi',
            WatchNotificationMode::Email => 'E-posta',
            WatchNotificationMode::InAppEmail => 'Uygulama içi + e-posta',
        };
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
