<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Marketplace\MarketplaceExternalSaleLink;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Routing\BasePath;

final class MarketplaceExternalSaleHtml
{
    /** @param list<string> $allowedHosts */
    public static function manage(
        MarketplaceListing $listing,
        ?MarketplaceExternalSaleLink $link,
        int $clickCount,
        array $allowedHosts,
        BasePath $basePath,
        string $csrf,
        bool $updated,
    ):string{
        $action=self::e($basePath->prepend('/marketplace/manage/external/'.$listing->listingId->value()));
        $hosts=$allowedHosts===[]?'Tanımlı izinli domain yok.':implode(', ',$allowedHosts);
        $body='<section class="card"><h1>Haricî Satış Bağlantısı</h1><p class="muted">İlan: '.self::e($listing->title).'</p>'
            .($updated?'<div class="search-alert market-success">Haricî satış ayarı kaydedildi.</div>':'')
            .'<p>Satış bağlantıları yalnız yönetici tarafından izin verilen HTTPS domainlerine gidebilir. '
            .'Ziyaretçi dış siteye gönderilmeden önce Forwext güvenlik uyarısını görür.</p>'
            .'<p class="muted">İzinli domainler: '.self::e($hosts).'</p>'
            .'<p class="muted">Kaydedilmiş yönlendirme tıklaması: '.$clickCount.'</p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<label class="search-wide"><span>Haricî satış URL</span><input type="url" name="target_url" maxlength="2048" '
            .'placeholder="https://shop.example.com/product" value="'.self::e($link?->targetUrl??'').'"></label>'
            .'<label><span>Durum</span><select name="enabled"><option value="0"'.($link?->enabled===true?'':' selected').'>Kapalı</option>'
            .'<option value="1"'.($link?->enabled===true?' selected':'').'>Aktif</option></select></label>'
            .'<div class="search-actions"><button type="submit">Kaydet</button></div></form>'
            .'<p class="muted">URL alanını boş kaydetmek mevcut haricî satış bağlantısını kaldırır.</p>'
            .'<p><a href="'.self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">İlan yönetimine dön</a></p></section>';
        return ProfileHtml::page('Haricî Satış Bağlantısı',$body,$basePath,authenticated:true);
    }

    public static function warning(
        MarketplaceListing $listing,
        MarketplaceExternalSaleLink $link,
        BasePath $basePath,
        string $csrf,
        bool $authenticated,
    ):string{
        $action=self::e($basePath->prepend('/marketplace/listings/'.$listing->listingId->value().'/external/go'));
        $body='<section class="card"><div class="search-hit-type">Haricî satış</div><h1>Forwext\'ten ayrılıyorsunuz</h1>'
            .'<p><strong>'.self::e($listing->title).'</strong> için satın alma işlemi Forwext dışında, satıcının seçtiği sitede devam edecek.</p>'
            .'<p class="muted">Hedef domain: <strong>'.self::e($link->targetHost).'</strong></p>'
            .'<p>Haricî sitenin ödeme, teslimat, gizlilik ve iade koşulları Forwext\'ten bağımsız olabilir. '
            .'Adres çubuğundaki domaini ve HTTPS bağlantısını kontrol edin.</p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<div class="search-actions"><button type="submit">Satıcı sitesine devam et</button>'
            .'<a href="'.self::e($basePath->prepend('/marketplace/listings/'.$listing->listingId->value())).'">İlana dön</a></div></form></section>';
        return ProfileHtml::page('Haricî Site Uyarısı',$body,$basePath,authenticated:$authenticated);
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function e(string $value):string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
