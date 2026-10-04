<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;

final class AccountPreferencesHtml
{
    public static function page(
        UserProfile $profile,
        PresenceVisibility $presence,
        ?NotificationSoundSettings $notifications,
        string $csrf,
        BasePath $basePath,
        bool $presenceUpdated,
    ): string {
        $profileSummary = [
            ['Profil', self::profileVisibility($profile->profileVisibility)],
            ['Hakkımda', self::profileVisibility($profile->aboutVisibility)],
            ['Sosyal bağlantılar', self::profileVisibility($profile->socialVisibility)],
            ['Profil medyası', self::profileVisibility($profile->mediaVisibility)],
        ];
        $privacyRows = '';
        foreach ($profileSummary as [$label, $value]) {
            $privacyRows .= '<div class="account-preference-summary-row"><span>' . self::e($label)
                . '</span><strong>' . self::e($value) . '</strong></div>';
        }

        $notificationSummary = $notifications === null
            ? '<div class="surface-empty">Bildirim tercihi bu hesap için kullanılamıyor.</div>'
            : '<div class="account-preference-summary-row"><span>Ses durumu</span><strong>'
                . ($notifications->muted ? 'Sessiz' : 'Açık') . '</strong></div>'
                . '<div class="account-preference-summary-row"><span>Ses seviyesi</span><strong>'
                . $notifications->volume . '%</strong></div>'
                . '<div class="account-preference-summary-row"><span>Varsayılan ses</span><strong>'
                . self::e($notifications->defaultSoundKey) . '</strong></div>';

        $content = '<section class="account-preferences-page discovery-page">'
            . '<header class="surface-head account-preferences-head"><div><span class="forum-eyebrow">HESAP</span>'
            . '<h1>Tercihler ve Gizlilik</h1>'
            . '<p>Profil görünürlüğü, çevrimiçi durumu ve bildirim tercihlerini mevcut hesap ayarlarından tek yerde izle.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesabıma dön</a></header>'
            . ($presenceUpdated
                ? '<div class="account-preferences-notice" role="status">Çevrimiçi görünürlük tercihin kaydedildi.</div>'
                : '')
            . '<div class="account-preferences-grid">';

        $content .= '<section class="surface-panel account-preference-card"><header><div><h2>Profil gizliliği</h2>'
            . '<p>Profilindeki mevcut görünürlük sınırları.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/profile')) . '">Düzenle</a></header>'
            . '<div class="account-preference-summary">' . $privacyRows . '</div></section>';

        $content .= '<section class="surface-panel account-preference-card" id="presence"><header><div><h2>Çevrimiçi görünürlük</h2>'
            . '<p>Aktif olduğunda kimlerin seni çevrimiçi görebileceğini belirle.</p></div></header>'
            . '<form class="account-presence-preference-form" method="post" action="'
            . self::e($basePath->prepend('/account/preferences')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_presence">'
            . '<label><span>Görünürlük</span><select name="presence_visibility">'
            . self::presenceOption(PresenceVisibility::Hidden, $presence, 'Gizli')
            . self::presenceOption(PresenceVisibility::Members, $presence, 'Yalnız üyeler')
            . self::presenceOption(PresenceVisibility::Public, $presence, 'Herkes')
            . '</select><small>Bu tercih mevcut çevrimiçi kullanıcı altyapısıyla aynı kaydı kullanır.</small></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Görünürlüğü kaydet</button></form></section>';

        $content .= '<section class="surface-panel account-preference-card"><header><div><h2>Bildirim davranışı</h2>'
            . '<p>Mevcut ses tercihlerinin özeti.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/notification-settings')) . '">Düzenle</a></header>'
            . '<div class="account-preference-summary">' . $notificationSummary . '</div></section>';

        $content .= '<section class="surface-panel account-preference-card"><header><div><h2>Hesap güvenliği</h2>'
            . '<p>Bağlı hesapları ve açık oturumları mevcut güvenlik yüzeylerinden yönet.</p></div></header>'
            . '<div class="account-preference-links">'
            . '<a href="' . self::e($basePath->prepend('/account/security')) . '"><strong>Güvenlik</strong>'
            . '<span>Bağlı giriş yöntemleri</span></a>'
            . '<a href="' . self::e($basePath->prepend('/account/sessions')) . '"><strong>Aktif oturumlar</strong>'
            . '<span>Açık cihazlar ve oturumlar</span></a>'
            . '<a href="' . self::e($basePath->prepend('/account/relationships')) . '"><strong>Takip ve engelleme</strong>'
            . '<span>Sosyal ilişki tercihleri</span></a></div></section>';

        $content .= '</div></section>';

        return ProfileHtml::page(
            'Tercihler ve Gizlilik',
            $content,
            $basePath,
            authenticated: true,
        );
    }

    private static function profileVisibility(ProfileVisibility $visibility): string
    {
        return match ($visibility) {
            ProfileVisibility::Public => 'Herkes',
            ProfileVisibility::Members => 'Yalnız üyeler',
            ProfileVisibility::Private => 'Yalnız ben',
        };
    }

    private static function presenceOption(
        PresenceVisibility $value,
        PresenceVisibility $selected,
        string $label,
    ): string {
        return '<option value="' . self::e($value->value) . '"'
            . ($value === $selected ? ' selected' : '') . '>' . self::e($label) . '</option>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
