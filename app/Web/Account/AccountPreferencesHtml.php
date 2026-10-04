<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;

final class AccountPreferencesHtml
{
    public static function page(
        UserProfile $profile,
        ProfileActivitySettings $activity,
        PresenceVisibility $presence,
        ?NotificationSoundSettings $notifications,
        BasePath $basePath,
    ): string {
        $cards = self::card(
            'Profil gizliliği',
            'Profil sayfası ve profil bölümlerinin kimler tarafından görülebileceğini yönet.',
            [
                'Profil' => self::profileVisibility($profile->profileVisibility),
                'Hakkımda' => self::profileVisibility($profile->aboutVisibility),
                'Sosyal bağlantılar' => self::profileVisibility($profile->socialVisibility),
                'Profil medyası' => self::profileVisibility($profile->mediaVisibility),
            ],
            $basePath->prepend('/account/profile'),
            'Profil ayarlarını aç',
        );

        $cards .= self::card(
            'Profil etkinliği',
            'Profil gönderileri ve profil duvarı erişim sınırlarını yönet.',
            [
                'Etkinliği görebilenler' => self::activityScope($activity->viewScope),
                'Profiline yazabilenler' => self::activityScope($activity->postScope),
            ],
            $basePath->prepend('/account/profile-activity'),
            'Etkinlik ayarlarını aç',
        );

        $cards .= self::card(
            'Çevrimiçi görünürlük',
            'Toplulukta çevrimiçi durumunun kimlere gösterileceğini yönet.',
            ['Görünürlük' => self::presenceVisibility($presence)],
            $basePath->prepend('/members/online#presence-settings'),
            'Görünürlüğü düzenle',
        );

        $notificationValues = $notifications === null
            ? ['Durum' => 'Bu hesap için kullanılamıyor']
            : [
                'Ses' => $notifications->muted ? 'Kapalı' : 'Açık',
                'Seviye' => $notifications->volume . '%',
                'Varsayılan ses' => $notifications->defaultSoundKey,
            ];
        $cards .= self::card(
            'Bildirim tercihleri',
            'Görsel bildirimlerden bağımsız olarak bildirim sesi davranışını yönet.',
            $notificationValues,
            $basePath->prepend('/account/notification-settings'),
            'Bildirim ayarlarını aç',
            $notifications !== null,
        );

        $cards .= self::card(
            'Yazım denetimi',
            'Kişisel sözlüğüne özel kelimeler ekle veya mevcut kelimeleri kaldır.',
            ['Sözlük' => 'Kişisel ve yetkin varsa site sözlüğü'],
            $basePath->prepend('/account/spellcheck-dictionary'),
            'Sözlüğü yönet',
        );

        $content = '<section class="account-preferences discovery-page">'
            . '<header class="surface-head account-preferences-head"><div><span class="forum-eyebrow">HESAP</span>'
            . '<h1>Tercihler ve Gizlilik</h1>'
            . '<p>Mevcut hesap ayarlarının özetini gör ve ilgili yönetim ekranına doğrudan geç.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account')) . '">Hesap merkezine dön</a></header>'
            . '<div class="account-preference-grid">' . $cards . '</div></section>';

        return ProfileHtml::page('Tercihler ve Gizlilik', $content, $basePath, authenticated: true);
    }

    /**
     * @param array<string,string> $values
     */
    private static function card(
        string $title,
        string $description,
        array $values,
        string $href,
        string $action,
        bool $enabled = true,
    ): string {
        $rows = '';
        foreach ($values as $label => $value) {
            $rows .= '<div class="account-preference-row"><span>' . self::e($label) . '</span><strong>'
                . self::e($value) . '</strong></div>';
        }

        return '<section class="surface-panel account-preference-card"><header><div><h2>' . self::e($title)
            . '</h2><p>' . self::e($description) . '</p></div></header>'
            . '<div class="account-preference-values">' . $rows . '</div>'
            . ($enabled
                ? '<a class="fx-btn" href="' . self::e($href) . '">' . self::e($action) . '</a>'
                : '')
            . '</section>';
    }

    private static function profileVisibility(ProfileVisibility $value): string
    {
        return match ($value) {
            ProfileVisibility::Public => 'Herkes',
            ProfileVisibility::Members => 'Yalnız üyeler',
            ProfileVisibility::Private => 'Yalnız ben',
        };
    }

    private static function activityScope(ProfileActivityScope $value): string
    {
        return match ($value) {
            ProfileActivityScope::Everyone => 'Herkes',
            ProfileActivityScope::Followers => 'Takipçiler',
            ProfileActivityScope::OwnerOnly => 'Yalnız ben',
        };
    }

    private static function presenceVisibility(PresenceVisibility $value): string
    {
        return match ($value) {
            PresenceVisibility::Hidden => 'Gizli',
            PresenceVisibility::Members => 'Yalnız üyeler',
            PresenceVisibility::Public => 'Herkes',
        };
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
