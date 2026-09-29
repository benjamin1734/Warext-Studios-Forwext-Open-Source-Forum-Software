<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Routing\BasePath;

final class NotificationSettingsHtml
{
    /** @param array<string,string> $presets */
    public static function page(
        NotificationSoundSettings $settings,
        array $presets,
        string $csrf,
        BasePath $basePath,
        bool $updated,
    ): string {
        $options = '';
        foreach ($presets as $key => $label) {
            $options .= '<option value="' . self::e($key) . '"'
                . ($settings->defaultSoundKey === $key ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }

        $notice = $updated
            ? '<div class="notification-settings-notice" role="status">Bildirim ayarları kaydedildi.</div>'
            : '';

        $content = '<section class="notification-settings discovery-page">'
            . '<header class="surface-head notification-settings-head"><div><span class="forum-eyebrow">HESAP</span>'
            . '<h1>Bildirim Ayarları</h1><p>Yeni bildirimlerin ses davranışını ve varsayılan bildirim sesini yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/account/notifications')) . '">Bildirimlere dön</a></header>'
            . $notice
            . '<form class="notification-settings-form surface-panel" method="post" action="'
            . self::e($basePath->prepend('/account/notification-settings')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save_sound">'
            . '<label class="notification-settings-switch"><input type="checkbox" name="muted" value="1"'
            . ($settings->muted ? ' checked' : '') . '><span><strong>Bildirim seslerini sustur</strong>'
            . '<small>Görsel bildirimler gelmeye devam eder; yalnız ses oynatılmaz.</small></span></label>'
            . '<label><span>Ses seviyesi <output data-notification-volume-output>'
            . $settings->volume . '</output>%</span><input type="range" min="0" max="100" step="1" name="volume" value="'
            . $settings->volume . '" data-notification-volume></label>'
            . '<label><span>Varsayılan bildirim sesi</span><select name="default_sound_key" data-notification-sound-select>'
            . $options . '</select></label>'
            . '<div class="notification-settings-actions"><button class="fx-btn" type="button" data-notification-preview>'
            . 'Sesi önizle</button><button class="fx-btn fx-btn--primary" type="submit">Ayarları kaydet</button></div>'
            . '</form>'
            . '<section class="notification-settings-info surface-panel"><h2>Nasıl çalışır?</h2>'
            . '<p>Ses çalma, tarayıcıların otomatik oynatma kurallarına tabidir. İlk kullanıcı etkileşiminden önce tarayıcı sesi engelleyebilir.</p>'
            . '<p>Kategoriye özel ses tercihleri mevcut altyapıda korunur; bu ekran varsayılan hesap ayarını yönetir.</p></section>'
            . '</section>';

        return ProfileHtml::page('Bildirim Ayarları', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
