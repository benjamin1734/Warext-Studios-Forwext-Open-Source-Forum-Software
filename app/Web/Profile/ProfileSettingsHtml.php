<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;

final class ProfileSettingsHtml
{
    public static function page(
        UserProfile $profile,
        string $username,
        string $csrf,
        BasePath $basePath,
        bool $updated = false,
        bool $error = false,
    ): string {
        $profileUrl = $basePath->prepend('/members/' . rawurlencode($username));
        $avatarUrl = $profileUrl . '/avatar';
        $bannerUrl = $profileUrl . '/banner';

        $content = '<section class="profile-settings-page discovery-page">'
            . '<header class="surface-head"><div><span class="surface-eyebrow">PROFİL</span>'
            . '<h1>Profil ve gizlilik</h1><p>Profil içeriğini, görünürlük sınırlarını ve profil görsellerini yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($profileUrl) . '">Profilimi görüntüle</a></header>'
            . ($updated ? '<div class="forum-notice">Profil ayarların kaydedildi.</div>' : '')
            . ($error ? '<div class="forum-compose-error">Ayarlar kaydedilemedi. Alanları veya yüklediğin görseli kontrol edip tekrar dene.</div>' : '')
            . '<div class="profile-settings-layout">';

        $content .= '<form class="surface-panel profile-settings-form" method="post" action="'
            . self::e($basePath->prepend('/account/profile')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_profile">'
            . '<header><div><h2>Profil bilgileri</h2><p>Hakkımda metni ve profil bölümlerinin kimler tarafından görülebileceğini belirle.</p></div></header>'
            . '<label class="profile-settings-wide"><span>Hakkımda</span>'
            . '<textarea name="about" rows="8" maxlength="5000">' . self::e($profile->about) . '</textarea>'
            . '<small>En fazla 5000 karakter.</small></label>'
            . self::visibilityField('profile_visibility', 'Profil görünürlüğü', $profile->profileVisibility, 'Profil sayfasının tamamına erişimi sınırlar.')
            . self::visibilityField('about_visibility', 'Hakkımda görünürlüğü', $profile->aboutVisibility, 'Hakkımda bölümünün görünürlüğünü belirler.')
            . self::visibilityField('social_visibility', 'Sosyal bağlantı görünürlüğü', $profile->socialVisibility, 'Sosyal bağlantıların kimlere gösterileceğini belirler.')
            . self::visibilityField('media_visibility', 'Profil medyası görünürlüğü', $profile->mediaVisibility, 'Avatar ve banner dosyalarının görüntülenmesini sınırlar.')
            . '<div class="profile-settings-actions"><button class="fx-btn fx-btn--primary" type="submit">Ayarları kaydet</button></div>'
            . '</form>';

        $content .= '<section class="surface-panel profile-media-settings"><header><div><h2>Profil görselleri</h2>'
            . '<p>JPEG, PNG veya WebP avatar ve banner kullanabilirsin.</p></div></header>'
            . '<div class="profile-media-setting-grid">'
            . self::mediaCard(
                'Avatar',
                'Kare görseller önerilir. En fazla 8 MB.',
                'avatar',
                $profile->avatarPath !== null,
                $avatarUrl,
                $csrf,
                $basePath,
            )
            . self::mediaCard(
                'Banner',
                'Geniş yatay görseller önerilir. En fazla 16 MB.',
                'banner',
                $profile->bannerPath !== null,
                $bannerUrl,
                $csrf,
                $basePath,
            )
            . '</div></section>';

        $content .= '</div></section>';

        return ProfileHtml::page(
            'Profil ve gizlilik',
            $content,
            $basePath,
            authenticated: true,
        );
    }

    private static function visibilityField(
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

    private static function mediaCard(
        string $title,
        string $description,
        string $kind,
        bool $available,
        string $previewUrl,
        string $csrf,
        BasePath $basePath,
    ): string {
        $action = self::e($basePath->prepend('/account/profile'));
        $preview = $available
            ? '<img src="' . self::e($previewUrl) . '" alt="' . self::e($title) . ' önizlemesi">'
            : '<span aria-hidden="true">Görsel yok</span>';

        return '<article class="profile-media-setting-card"><div class="profile-media-setting-preview profile-media-setting-preview--'
            . self::e($kind) . '">' . $preview . '</div><div class="profile-media-setting-copy"><h3>'
            . self::e($title) . '</h3><p>' . self::e($description) . '</p></div>'
            . '<form method="post" action="' . $action . '" enctype="multipart/form-data" class="profile-media-upload-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="upload_' . self::e($kind) . '">'
            . '<label class="fx-btn profile-media-picker">Yeni görsel seç<input type="file" name="media" accept="image/jpeg,image/png,image/webp" required></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Yükle</button></form>'
            . ($available
                ? '<form method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf)
                    . '"><input type="hidden" name="action" value="remove_' . self::e($kind)
                    . '"><button class="fx-btn" type="submit">Görseli kaldır</button></form>'
                : '')
            . '</article>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
