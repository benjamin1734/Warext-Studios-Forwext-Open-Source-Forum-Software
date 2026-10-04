<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Forum\State\WatchNotificationMode;

final class DiscussionWatchHtml
{
    public static function form(
        string $action,
        string $csrfToken,
        ?WatchNotificationMode $current,
    ): string {
        $selected = $current ?? WatchNotificationMode::None;
        $options = '';
        foreach ([
            [WatchNotificationMode::None, 'Takip etme'],
            [WatchNotificationMode::InApp, 'Uygulama içi'],
            [WatchNotificationMode::Email, 'E-posta'],
            [WatchNotificationMode::InAppEmail, 'Uygulama içi + e-posta'],
        ] as [$mode, $label]) {
            $options .= '<option value="' . self::e($mode->value) . '"'
                . ($selected === $mode ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }

        return '<form class="discussion-watch-form" method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<label><span class="sr-only">Takip bildirim modu</span><select name="mode">' . $options . '</select></label>'
            . '<button class="fx-btn" type="submit">Takibi güncelle</button></form>';
    }

    public static function markForumReadForm(string $action, string $csrfToken): string
    {
        return '<form class="discussion-mark-read-form" method="post" action="' . self::e($action) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<button class="fx-btn" type="submit">Forumu okundu işaretle</button></form>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
