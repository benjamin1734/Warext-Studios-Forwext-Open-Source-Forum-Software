<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Auth\OAuth\ConnectedAccount;
use Forwext\Core\Routing\BasePath;

final class AccountSecurityHtml
{
    /**
     * @param list<ConnectedAccount> $accounts
     * @param array<string,bool> $providerEnabled
     */
    public static function page(
        array $accounts,
        array $providerEnabled,
        string $csrf,
        BasePath $basePath,
        bool $linked,
        bool $unlinked,
    ): string {
        $byProvider = [];
        foreach ($accounts as $account) {
            $byProvider[$account->providerId] = $account;
        }

        $notice = '';
        if ($linked) {
            $notice = '<div class="account-security-notice" role="status">Hesap bağlantısı kaydedildi.</div>';
        } elseif ($unlinked) {
            $notice = '<div class="account-security-notice" role="status">Hesap bağlantısı kaldırıldı.</div>';
        }

        $providers = '';
        foreach (['google'=>'Google','discord'=>'Discord'] as $provider => $label) {
            $account = $byProvider[$provider] ?? null;
            $enabled = ($providerEnabled[$provider] ?? false) === true;
            if (!$enabled && !$account instanceof ConnectedAccount) {
                continue;
            }

            if ($account instanceof ConnectedAccount) {
                $identity = $account->displayName ?? $account->emailNormalized ?? 'Bağlı hesap';
                $providers .= '<article class="account-security-provider is-connected"><div>'
                    . '<strong>' . self::e($label) . '</strong><span>' . self::e($identity) . '</span>'
                    . '<small>Son doğrulama · ' . self::e($account->lastAuthenticatedAt->format('Y-m-d H:i')) . '</small></div>'
                    . '<form method="post" action="' . self::e($basePath->prepend('/account/security')) . '">'
                    . self::csrf($csrf) . '<input type="hidden" name="action" value="unlink">'
                    . '<input type="hidden" name="provider" value="' . self::e($provider) . '">'
                    . '<button class="fx-btn" type="submit">Bağlantıyı kaldır</button></form></article>';
                continue;
            }

            $providers .= '<article class="account-security-provider"><div><strong>' . self::e($label)
                . '</strong><span>Bağlı değil</span><small>Bu hesabı güvenli OAuth akışıyla bağlayabilirsin.</small></div>'
                . '<form method="post" action="' . self::e($basePath->prepend('/oauth/' . $provider . '/start')) . '">'
                . self::csrf($csrf) . '<input type="hidden" name="intent" value="link">'
                . '<button class="fx-btn fx-btn--primary" type="submit">Bağla</button></form></article>';
        }

        if ($providers === '') {
            $providers = '<div class="surface-empty">Etkin bir OAuth sağlayıcısı yapılandırılmamış.</div>';
        }

        $body = '<section class="account-security-page discovery-page"><header class="surface-head">'
            . '<div><span class="forum-eyebrow">GÜVENLİK</span><h1>Hesap Güvenliği</h1>'
            . '<p>Harici giriş yöntemlerini ve hesabına bağlı sağlayıcıları yönet.</p></div></header>'
            . $notice
            . '<section class="surface-panel account-security-panel"><div class="account-security-section-head">'
            . '<div><h2>Bağlı hesaplar</h2><p>Google veya Discord hesabını bağlayarak doğrulanmış sağlayıcı kimliğini hesabınla ilişkilendir.</p></div>'
            . '</div><div class="account-security-provider-list">' . $providers . '</div></section>'
            . '<section class="surface-panel account-security-help"><h2>Güvenlik notu</h2>'
            . '<p>OAuth erişim tokenları Forwext tarafından kalıcı olarak saklanmaz. Bağlantı işlemleri state + PKCE ile doğrulanır.</p>'
            . '</section></section>';

        return ProfileHtml::page('Hesap Güvenliği', $body, $basePath, authenticated: true);
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
