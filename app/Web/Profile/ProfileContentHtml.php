<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeZone;
use Forwext\Core\Profile\Content\UserForumContentItem;
use Forwext\Core\Profile\Content\UserForumContentType;
use Forwext\Core\Routing\BasePath;

final class ProfileContentHtml
{
    /**
     * @param list<UserForumContentItem> $items
     */
    public static function page(
        string $displayName,
        array $items,
        UserForumContentType $type,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $memberPath = ProfileHtml::memberPath($basePath, $displayName);
        $threadsPath = $memberPath . '/content/threads';
        $postsPath = $memberPath . '/content/posts';
        $title = $type === UserForumContentType::Thread
            ? $displayName . ' · Konular'
            : $displayName . ' · Mesajlar';

        $tabs = '<nav class="tabs surface-tabs profile-content-tabs" aria-label="Üye forum içeriği">'
            . '<a' . ($type === UserForumContentType::Thread ? ' aria-current="page"' : '')
            . ' href="' . ProfileHtml::escape($threadsPath) . '">Konular</a>'
            . '<a' . ($type === UserForumContentType::Post ? ' aria-current="page"' : '')
            . ' href="' . ProfileHtml::escape($postsPath) . '">Mesajlar</a></nav>';

        $rows = '';
        foreach ($items as $item) {
            $href = $basePath->prepend('/threads/' . rawurlencode($item->threadId->value()));
            if ($item->type === UserForumContentType::Post) {
                $href .= '#post-' . rawurlencode($item->contentId->value());
            }

            $rows .= '<article class="profile-forum-content-row"><div class="profile-forum-content-main">'
                . '<div class="profile-forum-content-kicker">' . ProfileHtml::escape($item->forumTitle)
                . ($item->position === null ? '' : ' · #' . number_format($item->position, 0, ',', '.'))
                . '</div><h2><a href="' . ProfileHtml::escape($href) . '">'
                . ProfileHtml::escape($item->threadTitle) . '</a></h2>'
                . ($item->excerpt === '' ? '' : '<p>' . ProfileHtml::escape($item->excerpt) . '</p>')
                . '</div><time datetime="' . ProfileHtml::escape($item->updatedAt->format(DATE_ATOM)) . '">'
                . ProfileHtml::escape($item->updatedAt->setTimezone($timezone)->format('d.m.Y H:i'))
                . '</time></article>';
        }

        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Görüntülenebilir içerik yok.</strong>'
                . '<span>Bu üyenin erişebildiğin forumlarda görünür '
                . ($type === UserForumContentType::Thread ? 'konusu' : 'mesajı') . ' bulunmuyor.</span></div>';
        }

        $pager = self::pager(
            $type === UserForumContentType::Thread ? $threadsPath : $postsPath,
            $page,
            $hasMore,
        );

        $content = '<section class="profile-content-discovery discovery-page"><header class="surface-head profile-content-head">'
            . '<div><span class="forum-eyebrow">ÜYE İÇERİĞİ</span><h1>' . ProfileHtml::escape($displayName) . '</h1>'
            . '<p>Erişebildiğin forumlardaki görünür içerikleri.</p></div>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($memberPath) . '">Profile dön</a></header>'
            . $tabs
            . '<section class="surface-panel profile-forum-content-panel"><div class="profile-forum-content-list">'
            . $rows . '</div>' . $pager . '</section></section>';

        return ProfileHtml::page(
            $title,
            $content,
            $basePath,
            authenticated: true,
        );
    }

    public static function authenticationRequired(BasePath $basePath): string
    {
        return ProfileHtml::page(
            'Üye içeriği',
            '<section class="profile-content-discovery discovery-page"><div class="surface-panel surface-empty">'
            . '<h1>Oturum gerekli</h1><p>Üye içerik listelerini görmek için giriş yapmalısın.</p>'
            . '<a class="fx-btn fx-btn--primary" href="' . ProfileHtml::escape($basePath->prepend('/login'))
            . '">Giriş yap</a></div></section>',
            $basePath,
        );
    }

    private static function pager(string $path, int $page, bool $hasMore): string
    {
        if ($page <= 1 && !$hasMore) {
            return '';
        }

        $html = '<nav class="surface-pager" aria-label="Sayfalama">';
        if ($page > 1) {
            $html .= '<a class="fx-btn" href="' . ProfileHtml::escape($path . '?page=' . ($page - 1))
                . '">← Önceki</a>';
        } else {
            $html .= '<span></span>';
        }
        $html .= '<span>Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a class="fx-btn" href="' . ProfileHtml::escape($path . '?page=' . ($page + 1))
                . '">Sonraki →</a>';
        } else {
            $html .= '<span></span>';
        }

        return $html . '</nav>';
    }
}
