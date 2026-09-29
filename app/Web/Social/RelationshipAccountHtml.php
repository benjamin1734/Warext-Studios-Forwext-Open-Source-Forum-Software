<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Social\Interaction\UserRelationshipEntry;

final class RelationshipAccountHtml
{
    /**
     * @param list<UserRelationshipEntry> $following
     * @param list<UserRelationshipEntry> $followers
     * @param list<UserRelationshipEntry> $ignored
     */
    public static function page(
        array $following,
        array $followers,
        array $ignored,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        $content = '<section class="relationship-center discovery-page"><header class="surface-head relationship-center-head">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>Sosyal İlişkiler</h1>'
            . '<p>Takip ettiğin, seni takip eden ve yok saydığın kullanıcıları tek yerden yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a></header>'
            . self::section('Takip Ettiklerin', $following, $basePath, $timezone, 'following')
            . self::section('Takipçilerin', $followers, $basePath, $timezone, 'followers')
            . self::section('Yok Sayılanlar', $ignored, $basePath, $timezone, 'ignored')
            . '</section>';

        return ProfileHtml::page('Sosyal İlişkiler', $content, $basePath, authenticated: true);
    }

    /** @param list<UserRelationshipEntry> $entries */
    private static function section(
        string $title,
        array $entries,
        BasePath $basePath,
        DateTimeZone $timezone,
        string $mode,
    ): string {
        $rows = '';
        foreach ($entries as $entry) {
            $profile = $basePath->prepend('/members/' . rawurlencode($entry->username->display()));
            $date = $entry->createdAt->setTimezone($timezone)->format('d.m.Y H:i');

            $controls = '';
            if ($mode === 'following' || $mode === 'ignored') {
                $following = $mode === 'following';
                $ignoring = $mode === 'ignored';
                $controls = '<div class="relationship-actions" data-user-relationship data-user-id="'
                    . self::e($entry->userId->value()) . '" data-following="' . ($following ? '1' : '0')
                    . '" data-ignoring="' . ($ignoring ? '1' : '0') . '">'
                    . '<button class="fx-btn" type="button" data-follow-toggle></button>'
                    . '<button class="fx-btn" type="button" data-ignore-toggle></button>'
                    . '<span class="relationship-status" data-relationship-status role="status" aria-live="polite"></span>'
                    . '</div>';
            }

            $rows .= '<article class="relationship-row"><div><a class="relationship-user" href="'
                . self::e($profile) . '">' . self::e($entry->username->display()) . '</a>'
                . '<span class="muted">' . self::e($date) . '</span></div>' . $controls . '</article>';
        }

        if ($rows === '') {
            $rows = '<div class="relationship-empty">Bu bölümde kullanıcı bulunmuyor.</div>';
        }

        return '<section class="relationship-panel surface-panel"><div class="relationship-panel-head"><h2>'
            . self::e($title) . '</h2><span>' . count($entries) . '</span></div><div class="relationship-list">'
            . $rows . '</div></section>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
