<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Auth\Session\AuthSessionIndexRecord;
use Forwext\Core\Routing\BasePath;

final class AccountSessionsHtml
{
    /** @param list<AuthSessionIndexRecord> $sessions */
    public static function page(
        array $sessions,
        string $currentHash,
        string $csrf,
        BasePath $basePath,
        DateTimeZone $timezone,
        bool $updated = false,
    ): string {
        $rows = '';
        foreach ($sessions as $session) {
            $current = hash_equals($currentHash, $session->sessionHash);
            $device = substr($session->deviceId, 0, 8);
            $rows .= '<article class="account-session-row' . ($current ? ' is-current' : '') . '">'
                . '<div class="account-session-main"><div class="account-session-title"><strong>Cihaz '
                . self::e($device) . '</strong>' . ($current ? '<span class="thread-badge thread-badge--accent">Bu oturum</span>' : '')
                . '</div><div class="account-session-meta"><span>Başlangıç · '
                . self::e($session->issuedAt->setTimezone($timezone)->format('d.m.Y H:i')) . '</span>'
                . '<span>Son etkinlik · ' . self::e($session->lastSeenAt->setTimezone($timezone)->format('d.m.Y H:i')) . '</span>'
                . '<span>Bitiş · ' . self::e($session->expiresAt->setTimezone($timezone)->format('d.m.Y H:i')) . '</span></div></div>';

            if (!$current) {
                $rows .= '<form method="post" action="' . self::e($basePath->prepend('/account/sessions')) . '">'
                    . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                    . '<input type="hidden" name="action" value="revoke_session">'
                    . '<input type="hidden" name="session_hash" value="' . self::e($session->sessionHash) . '">'
                    . '<button class="fx-btn" type="submit">Oturumu kapat</button></form>';
            } else {
                $rows .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/logout')) . '">Çıkış yap</a>';
            }
            $rows .= '</article>';
        }

        if ($rows === '') {
            $rows = '<div class="empty">Aktif oturum bulunamadı.</div>';
        }

        $otherCount = count(array_filter(
            $sessions,
            static fn (AuthSessionIndexRecord $session): bool => !hash_equals($currentHash, $session->sessionHash),
        ));
        $bulk = $otherCount > 0
            ? '<form method="post" action="' . self::e($basePath->prepend('/account/sessions')) . '" class="account-session-bulk">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="revoke_others">'
                . '<button class="fx-btn" type="submit">Diğer ' . $otherCount . ' oturumu kapat</button></form>'
            : '';

        $content = '<section class="account-sessions discovery-page"><header class="surface-head"><div>'
            . '<span class="surface-eyebrow">GÜVENLİK</span><h1>Aktif oturumlar</h1>'
            . '<p>Hesabına açık olan oturumları incele ve artık kullanmadığın oturumları kapat.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a></header>'
            . ($updated ? '<div class="forum-notice">Oturum değişikliği uygulandı.</div>' : '')
            . '<section class="surface-panel account-session-panel"><header><div><h2>Oturumlar</h2>'
            . '<p>Güvenlik nedeniyle ham session tokenları hiçbir zaman gösterilmez veya saklanmaz.</p></div>'
            . $bulk . '</header><div class="account-session-list">' . $rows . '</div></section></section>';

        return ProfileHtml::page('Aktif oturumlar', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
