<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;

final class AccountPrivacyHtml
{
    public static function page(
        UserProfile $profile,
        PresenceVisibility $presence,
        string $csrf,
        BasePath $basePath,
        ?string $updated = null,
        bool $error = false,
    ): string {
        $action = self::e($basePath->prepend('/account/privacy'));

        $content = '<section class="account-privacy discovery-page">'
            . '<header class="surface-head account-privacy-head"><div><span class="forum-eyebrow">HESAP</span>'
            . '<h1>Gizlilik ve görünürlük</h1>'
            . '<p>Profilinin hangi bölümlerini kimlerin görebileceğini ve çevrimiçi görünürlüğünü yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a></header>'
            . ($updated === 'profile' ? '<div class="forum-notice">Profil görünürlüğü güncellendi.</div>' : '')
            . ($updated === 'presence' ? '<div class="forum-notice">Çevrimiçi görünürlük güncellendi.</div>' : '')
            . ($error ? '<div class="forum-compose-error">Gizlilik ayarları kaydedilemedi. Seçimleri kontrol edip tekrar dene.</div>' : '')
            . '<div class="account-privacy-grid">';

        $content .= '<form class="surface-panel account-privacy-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_profile_visibility">'
            . '<header><div><h2>Profil görünürlüğü</h2>'
            . '<p>Profil, hakkımda, sosyal bağlantılar ve profil medyası için ayrı sınırlar belirle.</p></div></header>'
            . self::profileVisibilityField(
                'profile_visibility',
                'Profil sayfası',
                $profile->profileVisibility,
                'Profil sayfasının tamamına erişimi belirler.',
            )
            . self::profileVisibilityField(
                'about_visibility',
                'Hakkımda',
                $profile->aboutVisibility,
                'Hakkımda bölümünü kimlerin görebileceğini belirler.',
            )
            . self::profileVisibilityField(
                'social_visibility',
                'Sosyal bağlantılar',
                $profile->socialVisibility,
                'Profildeki sosyal bağlantıların görünürlüğünü belirler.',
            )
            . self::profileVisibilityField(
                'media_visibility',
                'Avatar ve banner',
                $profile->mediaVisibility,
                'Profil medyasına erişimi sınırlar.',
            )
            . '<div class="account-privacy-actions"><button class="fx-btn fx-btn--primary" type="submit">'
            . 'Profil gizliliğini kaydet</button></div></form>';

        $content .= '<form class="surface-panel account-privacy-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_presence_visibility">'
            . '<header><div><h2>Çevrimiçi görünürlük</h2>'
            . '<p>Aktif kullanıcı listelerinde çevrimiçi durumunun kimlere gösterileceğini seç.</p></div></header>'
            . '<label><span>Çevrimiçi durum</span><select name="presence_visibility">'
            . self::presenceOption(PresenceVisibility::Public, 'Herkes', $presence)
            . self::presenceOption(PresenceVisibility::Members, 'Yalnızca üyeler', $presence)
            . self::presenceOption(PresenceVisibility::Hidden, 'Gizli', $presence)
            . '</select><small>Gizli seçiminde çevrimiçi listelerinde görünmezsin.</small></label>'
            . '<div class="account-privacy-actions"><button class="fx-btn fx-btn--primary" type="submit">'
            . 'Çevrimiçi görünürlüğü kaydet</button></div></form>';

        $content .= '</div><nav class="account-privacy-links" aria-label="İlgili hesap ayarları">'
            . '<a href="' . self::e($basePath->prepend('/account/profile')) . '">Profil ve kimlik</a>'
            . '<a href="' . self::e($basePath->prepend('/account/security')) . '">Güvenlik</a>'
            . '<a href="' . self::e($basePath->prepend('/account/relationships')) . '">Takip ve engelleme</a>'
            . '<a href="' . self::e($basePath->prepend('/account/notification-settings')) . '">Bildirim ayarları</a>'
            . '</nav></section>';

        return ProfileHtml::page(
            'Gizlilik ve görünürlük',
            $content,
            $basePath,
            authenticated: true,
        );
    }

    private static function profileVisibilityField(
        string $name,
        string $label,
        ProfileVisibility $selected,
        string $description,
    ): string {
        $options = '';
        foreach ([
            [ProfileVisibility::Public, 'Herkes'],
            [ProfileVisibility::Members, 'Yalnızca üyeler'],
            [ProfileVisibility::Private, 'Yalnızca ben'],
        ] as [$visibility, $caption]) {
            $options .= '<option value="' . self::e($visibility->value) . '"'
                . ($visibility === $selected ? ' selected' : '') . '>' . self::e($caption) . '</option>';
        }

        return '<label><span>' . self::e($label) . '</span><select name="' . self::e($name) . '">'
            . $options . '</select><small>' . self::e($description) . '</small></label>';
    }

    private static function presenceOption(
        PresenceVisibility $value,
        string $label,
        PresenceVisibility $selected,
    ): string {
        return '<option value="' . self::e($value->value) . '"'
            . ($value === $selected ? ' selected' : '') . '>' . self::e($label) . '</option>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
