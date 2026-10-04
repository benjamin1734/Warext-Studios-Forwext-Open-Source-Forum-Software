<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeZone;
use Forwext\Core\Profile\Activity\ActivityFeedEntry;
use Forwext\Core\Profile\Activity\ActivityFeedType;
use Forwext\Core\Routing\BasePath;

final class ActivityFeedHtml
{
    /** @param list<ActivityFeedEntry> $items */
    public static function page(
        array $items,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        DateTimeZone $timezone,
        string $title = 'Neler yeni?',
        string $description = 'Erişebildiğin forum ve profil hareketlerini kronolojik olarak takip et.',
        string $routePath = '/activity',
    ): string {
        $rows = '';
        foreach ($items as $entry) {
            $label = self::label($entry->type);
            $time = $entry->occurredAt->setTimezone($timezone);
            $action = self::action($entry, $basePath);

            $rows .= '<article class="activity-feed-item"><div class="activity-feed-icon" aria-hidden="true">•</div>'
                . '<div class="activity-feed-main"><div class="activity-feed-meta"><span>' . self::e($label)
                . '</span><time datetime="' . self::e($entry->occurredAt->format(DATE_ATOM)) . '">'
                . self::e($time->format('d.m.Y H:i')) . '</time></div>'
                . '<p>' . self::e($entry->summary) . '</p></div>'
                . ($action === null ? '' : '<a class="fx-btn" href="' . self::e($action) . '">Aç</a>')
                . '</article>';
        }

        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Görüntülenebilir etkinlik yok.</strong>'
                . '<span>Takip ettiğin ve erişebildiğin içerik hareketleri burada görünecek.</span></div>';
        }

        $pager = '<nav class="surface-pagination activity-pagination" aria-label="Etkinlik sayfaları">';
        if ($page > 1) {
            $pager .= '<a class="fx-btn" href="' . self::e($basePath->prepend($routePath . '?page=' . ($page - 1)))
                . '">Önceki</a>';
        }
        if ($hasMore) {
            $pager .= '<a class="fx-btn" href="' . self::e($basePath->prepend($routePath . '?page=' . ($page + 1)))
                . '">Sonraki</a>';
        }
        $pager .= '</nav>';

        $content = '<section class="activity-feed discovery-page"><header class="surface-head activity-feed-head">'
            . '<div><h1>' . self::e($title) . '</h1>'
            . '<p>' . self::e($description) . '</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/profile-activity')) . '">Akış ayarları</a>'
            . '</header><section class="surface-panel activity-feed-panel"><div class="activity-feed-list">'
            . $rows . '</div>' . $pager . '</section></section>';

        return ProfileHtml::page($title, $content, $basePath, authenticated: true);
    }

    private static function label(ActivityFeedType $type): string
    {
        return match ($type) {
            ActivityFeedType::ThreadCreated => 'Yeni konu',
            ActivityFeedType::ForumPostCreated => 'Forum mesajı',
            ActivityFeedType::ProfilePostCreated => 'Profil gönderisi',
            ActivityFeedType::ProfileCommentCreated => 'Profil yorumu',
            ActivityFeedType::ProfileReaction => 'Profil tepkisi',
        };
    }

    private static function action(ActivityFeedEntry $entry, BasePath $basePath): ?string
    {
        return match ($entry->type) {
            ActivityFeedType::ThreadCreated => $basePath->prepend('/threads/' . rawurlencode($entry->subjectId->value())),
            default => null,
        };
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
