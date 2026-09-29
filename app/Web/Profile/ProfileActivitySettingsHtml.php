<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Routing\BasePath;

final class ProfileActivitySettingsHtml
{
    public static function page(
        ProfileActivitySettings $settings,
        string $csrf,
        BasePath $basePath,
        bool $updated,
    ): string {
        $notice = $updated
            ? '<div class="notification-settings-notice" role="status">Profil etkinliği ayarları kaydedildi.</div>'
            : '';

        $content = '<section class="profile-activity-settings discovery-page"><header class="surface-head profile-activity-head">'
            . '<div><span class="forum-eyebrow">GİZLİLİK</span><h1>Profil Etkinliği</h1>'
            . '<p>Profil hareketlerini kimlerin görebileceğini ve profiline kimlerin yazabileceğini belirle.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/activity')) . '">Neler yeni?</a></header>'
            . $notice
            . '<form class="notification-settings-form surface-panel profile-activity-form" method="post" action="'
            . self::e($basePath->prepend('/account/profile-activity')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_activity_settings">'
            . '<label><span>Profil etkinliğini kimler görebilir?</span><select name="view_scope">'
            . self::options($settings->viewScope) . '</select></label>'
            . '<label><span>Profiline kimler gönderi yazabilir?</span><select name="post_scope">'
            . self::options($settings->postScope) . '</select></label>'
            . '<div class="notification-settings-actions"><a class="fx-btn" href="'
            . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Ayarları kaydet</button></div>'
            . '</form></section>';

        return ProfileHtml::page('Profil Etkinliği', $content, $basePath, authenticated: true);
    }

    private static function options(ProfileActivityScope $selected): string
    {
        $labels = [
            ProfileActivityScope::Everyone->value => 'Herkes',
            ProfileActivityScope::Followers->value => 'Takipçiler',
            ProfileActivityScope::OwnerOnly->value => 'Yalnızca ben',
        ];

        $html = '';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . self::e($value) . '"'
                . ($selected->value === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }

        return $html;
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
