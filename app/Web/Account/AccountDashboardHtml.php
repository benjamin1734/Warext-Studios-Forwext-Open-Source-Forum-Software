<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;

final class AccountDashboardHtml
{
    public static function page(BasePath $basePath): string
    {
        $groups = [
            'communication' => [
                'label' => 'Bildirim ve içerik',
                'description' => 'Bildirimlerini, akışını ve kaydettiğin içerikleri yönet.',
                'items' => [
                    ['Özel Mesajlar', 'Topluluk üyeleriyle özel konuşmalarını görüntüle ve yeni mesaj gönder.', '/account/conversations'],
                    ['Bildirimler', 'Okunmamış ve geçmiş bildirimlerini görüntüle.', '/account/notifications'],
                    ['Bildirim Ayarları', 'Bildirim sesini, ses seviyesini ve varsayılan sesi yönet.', '/account/notification-settings'],
                    ['Neler yeni?', 'Erişebildiğin forum ve profil hareketlerini takip et.', '/activity'],
                    ['Yer İmleri', 'Kaydettiğin forum içeriklerini görüntüle.', '/account/bookmarks'],
                ],
            ],
            'profile' => [
                'label' => 'Profil ve sosyal',
                'description' => 'Profil görünümünü, etkinliğini ve topluluk ilişkilerini düzenle.',
                'items' => [
                    ['Profil ve Gizlilik', 'Hakkımda, görünürlük, avatar ve banner ayarlarını yönet.', '/account/profile'],
                    ['Profil URL', 'Profiline ait özel URL ayarlarını yönet.', '/account/profile-url'],
                    ['Profil Etkinliği', 'Profil gönderileri ve etkinlik tercihlerini yönet.', '/account/profile-activity'],
                    ['Sosyal İlişkiler', 'Takip ettiğin, seni takip eden ve yok saydığın kullanıcıları yönet.', '/account/relationships'],
                    ['Davetlerim', 'Referans bağlantılarını ve davet kazanımlarını takip et.', '/account/referrals'],
                ],
            ],
            'account' => [
                'label' => 'Hesap ve araçlar',
                'description' => 'Üyelik, kişisel araçlar ve hesap durumuyla ilgili alanlar.',
                'items' => [
                    ['Hesap Güvenliği', 'Google/Discord bağlantılarını ve harici giriş yöntemlerini yönet.', '/account/security'],
                    ['Aktif Oturumlar', 'Hesabına açık oturumları incele ve diğer cihazlardaki oturumları kapat.', '/account/sessions'],
                    ['Üyelik Yükseltmeleri', 'Hesabına uygun planları ve üyelik yükseltmelerini görüntüle.', '/account/upgrades'],
                    ['Yazım Sözlüğü', 'Kişisel yazım denetimi sözlüğünü yönet.', '/account/spellcheck-dictionary'],
                    ['Disiplin Geçmişi', 'Hesabına uygulanan uyarı ve kısıtlamaları görüntüle.', '/account/discipline'],
                    ['Hata Bildirimlerim', 'Daha önce gönderdiğin hata bildirimlerini takip et.', '/bugs'],
                ],
            ],
        ];

        $nav = '';
        $sections = '';
        foreach ($groups as $key => $group) {
            $nav .= '<a href="#' . self::e($key) . '"><span>' . self::e($group['label'])
                . '</span><small>' . count($group['items']) . '</small></a>';

            $items = '';
            foreach ($group['items'] as [$title, $description, $path]) {
                $items .= '<a class="account-setting-row" href="' . self::e($basePath->prepend($path)) . '">'
                    . '<span class="account-setting-copy"><strong>' . self::e($title) . '</strong><small>'
                    . self::e($description) . '</small></span><span class="account-setting-arrow" aria-hidden="true">→</span>'
                    . '</a>';
            }

            $sections .= '<section class="account-settings-group" id="' . self::e($key) . '">'
                . '<header><div><h2>' . self::e($group['label']) . '</h2><p>'
                . self::e($group['description']) . '</p></div></header>'
                . '<div class="account-setting-list">' . $items . '</div></section>';
        }

        $content = '<section class="account-center discovery-page"><header class="surface-head account-center-head">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>Hesabım</h1>'
            . '<p>Profil, bildirim, topluluk ve üyelik ayarlarını tek merkezden yönet.</p></div>'
            . '</header><div class="account-center-layout"><aside class="account-center-nav surface-panel">'
            . '<strong>Ayarlar</strong><nav aria-label="Hesap ayar bölümleri">' . $nav . '</nav></aside>'
            . '<div class="account-center-sections">' . $sections . '</div></div></section>';

        return ProfileHtml::page('Hesabım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
