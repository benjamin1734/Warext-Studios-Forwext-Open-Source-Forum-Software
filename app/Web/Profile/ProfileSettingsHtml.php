<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

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
            . '<h1>Profil ve kimlik</h1><p>Hakkımda içeriğini ve profil görsellerini yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($profileUrl) . '">Profilimi görüntüle</a></header>'
            . ($updated ? '<div class="forum-notice">Profil ayarların kaydedildi.</div>' : '')
            . ($error ? '<div class="forum-compose-error">Ayarlar kaydedilemedi. Alanları veya yüklediğin görseli kontrol edip tekrar dene.</div>' : '')
            . '<div class="profile-settings-layout">';

        $content .= '<form class="surface-panel profile-settings-form" method="post" action="'
            . self::e($basePath->prepend('/account/profile')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_profile">'
            . '<header><div><h2>Profil bilgileri</h2><p>Profilinde gösterilecek Hakkımda metnini düzenle.</p></div></header>'
            . '<label class="profile-settings-wide"><span>Hakkımda</span>'
            . '<textarea name="about" rows="8" maxlength="5000">' . self::e($profile->about) . '</textarea>'
            . '<small>En fazla 5000 karakter.</small></label>'
            . '<div class="profile-settings-privacy-link"><span>Görünürlük ayarları ayrı gizlilik merkezinden yönetilir.</span>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/privacy')) . '">Gizlilik ayarları</a></div>'
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
            'Profil ve kimlik',
            $content,
            $basePath,
            authenticated: true,
        );
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
