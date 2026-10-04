<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Routing\BasePath;

final class NotificationInboxHtml
{
    /**
     * @param list<Notification> $notifications
     */
    public static function page(
        array $notifications,
        int $unreadCount,
        int $page,
        bool $hasMore,
        string $csrf,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $items = '';
        foreach ($notifications as $notification) {
            $unread = $notification->readAt === null;
            $actionPath = self::safeActionPath($notification->actionPath);
            $action = $actionPath === null
                ? ''
                : '<a class="fx-btn notification-open" href="' . self::e($basePath->prepend($actionPath)) . '">Aç</a>';
            $occurrences = $notification->occurrences > 1
                ? '<span class="notification-count">×' . $notification->occurrences . '</span>'
                : '';
            $readForm = $unread
                ? '<form method="post" action="' . self::e($basePath->prepend('/account/notifications')) . '">'
                    . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                    . '<input type="hidden" name="action" value="mark_read">'
                    . '<input type="hidden" name="notification_id" value="' . self::e($notification->id->value()) . '">'
                    . '<input type="hidden" name="page" value="' . $page . '">'
                    . '<button class="fx-btn" type="submit">Okundu işaretle</button></form>'
                : '<span class="notification-read-state">Okundu</span>';

            $updated = $notification->updatedAt->setTimezone($timezone);
            $items .= '<article class="notification-item' . ($unread ? ' is-unread' : '') . '">'
                . '<div class="notification-main"><div class="notification-meta"><span>'
                . self::e($notification->categoryKey) . '</span><time datetime="'
                . self::e($notification->updatedAt->format(DATE_ATOM)) . '">'
                . self::e($updated->format('d.m.Y H:i')) . '</time>' . $occurrences . '</div>'
                . '<h2>' . self::e($notification->title) . '</h2><p>' . self::e($notification->body) . '</p></div>'
                . '<div class="notification-actions">' . $action . $readForm . '</div></article>';
        }

        if ($items === '') {
            $items = '<div class="surface-empty notification-empty"><strong>Bildirim bulunmuyor.</strong>'
                . '<span>Yeni bildirimler geldiğinde burada listelenecek.</span></div>';
        }

        $pagination = '<nav class="surface-pagination notification-pagination" aria-label="Bildirim sayfaları">';
        if ($page > 1) {
            $pagination .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/notifications?page=' . ($page - 1)))
                . '">Önceki</a>';
        }
        if ($hasMore) {
            $pagination .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/notifications?page=' . ($page + 1)))
                . '">Sonraki</a>';
        }
        $pagination .= '</nav>';

        $bulkRead = $unreadCount > 0
            ? '<form class="notification-bulk-read" method="post" action="'
                . self::e($basePath->prepend('/account/notifications')) . '">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="mark_all_read">'
                . '<input type="hidden" name="page" value="' . $page . '">'
                . '<button class="fx-btn" type="submit">Tümünü okundu işaretle</button></form>'
            : '';

        $content = '<section class="notification-center discovery-page"><header class="surface-head notification-head">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>Bildirimler</h1>'
            . '<p>Forum, destek, Marketplace, moderasyon ve diğer sistem bildirimlerini takip et.</p></div>'
            . '<div class="notification-head-actions"><div class="notification-unread"><strong>' . $unreadCount
            . '</strong><span>okunmamış</span></div>' . $bulkRead . '<a class="fx-btn" href="'
            . self::e($basePath->prepend('/account/notification-settings')) . '">Ayarlar</a></div></header>'
            . '<section class="surface-panel notification-panel"><div class="notification-list">' . $items
            . '</div>' . $pagination . '</section></section>';

        return ProfileHtml::page(
            'Bildirimler',
            $content,
            $basePath,
            authenticated: true,
        );
    }

    private static function safeActionPath(?string $path): ?string
    {
        if ($path === null || $path === '' || strlen($path) > 1000
            || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return null;
        }

        return $path;
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
