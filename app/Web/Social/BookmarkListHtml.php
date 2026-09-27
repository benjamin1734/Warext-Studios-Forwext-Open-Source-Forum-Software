<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Social\Interaction\BookmarkEntry;

final class BookmarkListHtml
{
    /** @param list<BookmarkEntry> $bookmarks */
    public static function page(
        array $bookmarks,
        int $page,
        bool $hasMore,
        BasePath $basePath,
    ): string {
        $items = '';
        foreach ($bookmarks as $bookmark) {
            $title = $bookmark->threadTitle !== null && trim($bookmark->threadTitle) !== ''
                ? $bookmark->threadTitle
                : 'Kaydedilmiş mesaj';
            $link = null;
            if ($bookmark->threadId !== null) {
                $link = $basePath->prepend('/threads/' . rawurlencode($bookmark->threadId->value()))
                    . '#post-' . rawurlencode($bookmark->postId->value());
            }

            $note = $bookmark->note === null || trim($bookmark->note) === ''
                ? '<span class="bookmark-note muted">Not eklenmemiş.</span>'
                : '<p class="bookmark-note">' . self::e($bookmark->note) . '</p>';

            $items .= '<article class="bookmark-card card"><div class="bookmark-card-main">'
                . '<span class="forum-eyebrow">YER İMİ</span><h2>' . self::e($title) . '</h2>'
                . '<div class="bookmark-meta"><span>Mesaj #' . self::e((string) ($bookmark->postPosition ?? '?')) . '</span>'
                . '<span>ID: ' . self::e($bookmark->postId->value()) . '</span></div>'
                . $note . '</div>'
                . ($link === null ? '' : '<a class="fx-btn" href="' . self::e($link) . '">Mesaja git</a>')
                . '</article>';
        }

        if ($items === '') {
            $items = '<div class="bookmark-empty card"><strong>Henüz yer imi yok.</strong>'
                . '<span>Forum mesajlarını kaydettiğinde burada listelenecek.</span></div>';
        }

        $pager = '<nav class="notification-pagination" aria-label="Yer imi sayfaları">';
        if ($page > 1) {
            $pager .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/bookmarks?page=' . ($page - 1)))
                . '">Önceki</a>';
        }
        if ($hasMore) {
            $pager .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/bookmarks?page=' . ($page + 1)))
                . '">Sonraki</a>';
        }
        $pager .= '</nav>';

        $content = '<section class="bookmark-center"><header class="account-center-hero card">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>Yer İmleri</h1>'
            . '<p>Kaydettiğin forum mesajlarını ve kişisel notlarını tek yerde görüntüle.</p></div>'
            . '</header><div class="bookmark-list">' . $items . '</div>' . $pager . '</section>';

        return ProfileHtml::page('Yer İmleri', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
