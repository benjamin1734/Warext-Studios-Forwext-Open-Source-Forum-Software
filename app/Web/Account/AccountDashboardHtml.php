<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;

final class AccountDashboardHtml
{
    public static function page(BasePath $basePath): string
    {
        $cards = [
            ['Bildirimler', 'Okunmamış ve geçmiş bildirimlerini görüntüle.', '/account/notifications', 'Bildirimleri aç'],
            ['Bildirim Ayarları', 'Bildirim sesini, ses seviyesini ve varsayılan sesi yönet.', '/account/notification-settings', 'Ayarları aç'],
            ['Profil URL', 'Profiline ait özel URL ayarlarını yönet.', '/account/profile-url', 'Profil URL ayarları'],
            ['Profil Etkinliği', 'Profil gönderileri ve etkinlik tercihlerini yönet.', '/account/profile-activity', 'Etkinlik ayarları'],
            ['Etkinlik Akışı', 'Erişebildiğin forum ve profil hareketlerini takip et.', '/activity', 'Akışı aç'],
            ['Yer İmleri', 'Kaydettiğin forum içeriklerini görüntüle.', '/account/bookmarks', 'Yer imlerini aç'],
            ['Sosyal İlişkiler', 'Takip ettiğin, seni takip eden ve yok saydığın kullanıcıları yönet.', '/account/relationships', 'İlişkileri yönet'],
            ['Davetlerim', 'Referans bağlantılarını ve davet kazanımlarını takip et.', '/account/referrals', 'Davetleri aç'],
            ['Upgrades', 'Hesabına uygun planları ve üyelik yükseltmelerini görüntüle.', '/account/upgrades', 'Planları aç'],
            ['Yazım Sözlüğü', 'Kişisel yazım denetimi sözlüğünü yönet.', '/account/spellcheck-dictionary', 'Sözlüğü aç'],
            ['Disiplin Geçmişi', 'Hesabına uygulanan uyarı ve kısıtlamaları görüntüle.', '/account/discipline', 'Geçmişi aç'],
            ['Hata Bildirimlerim', 'Daha önce gönderdiğin hata bildirimlerini takip et.', '/bugs', 'Hata bildirimlerini aç'],
        ];

        $grid = '';
        foreach ($cards as [$title, $description, $path, $action]) {
            $grid .= '<article class="account-center-card card"><div><h2>' . self::e($title) . '</h2><p>'
                . self::e($description) . '</p></div><a class="fx-btn" href="'
                . self::e($basePath->prepend($path)) . '">' . self::e($action) . '</a></article>';
        }

        $content = '<section class="account-center"><header class="account-center-hero card">'
            . '<div><span class="forum-eyebrow">HESAP</span><h1>Hesabım</h1>'
            . '<p>Forwext hesabına ait ayarları, bildirimleri ve kişisel araçları tek merkezden yönet.</p></div>'
            . '</header><div class="account-center-grid">' . $grid . '</div></section>';

        return ProfileHtml::page('Hesabım', $content, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
