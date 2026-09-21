<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryListingSetting;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryRecord;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Routing\BasePath;

final class MarketplaceDeliveryHtml
{
    public static function manage(
        MarketplaceListing $listing,
        ?MarketplaceDeliveryListingSetting $setting,
        int $keyCount,
        BasePath $basePath,
        string $csrf,
        bool $updated,
    ):string{
        $type=$setting?->type->value??'manual';
        $action=self::e($basePath->prepend('/marketplace/manage/delivery/'.$listing->listingId->value()));
        $body='<section class="card"><h1>Dijital Teslimat</h1><p class="muted">İlan: '.self::e($listing->title).'</p>'
            .($updated?'<div class="search-alert market-success">Teslimat ayarları güncellendi.</div>':'')
            .'<dl class="market-specs"><dt>Aktif tür</dt><dd>'.self::e($type).'</dd>'
            .'<dt>Kullanılabilir anahtar</dt><dd>'.$keyCount.'</dd></dl>'
            .'<section class="section"><h2>Teslimat türü</h2><form method="post" action="'.$action.'" class="search-form">'
            .self::csrf($csrf).'<input type="hidden" name="action" value="configure">'
            .'<label><span>Tür</span><select name="delivery_type">'
            .self::option('manual','Manuel teslimat',$type).self::option('license','Lisans',$type)
            .self::option('key','Anahtar',$type).'</select></label>'
            .'<div class="search-actions"><button type="submit">Türü kaydet</button></div></form></section>'
            .'<section class="section"><h2>Dosya teslimatı</h2><p class="muted">ZIP, PDF, metin veya güvenli görsel yükleyin. Dosya private storage alanında tutulur.</p>'
            .'<form method="post" enctype="multipart/form-data" action="'.$action.'" class="search-form">'
            .self::csrf($csrf).'<input type="hidden" name="action" value="upload">'
            .'<label class="search-wide"><span>Teslimat dosyası</span><input type="file" name="file" required></label>'
            .'<div class="search-actions"><button type="submit">Dosyayı yükle ve aktif et</button></div></form></section>'
            .'<section class="section"><h2>Lisans / anahtar havuzu</h2><p class="muted">Her satıra bir değer girin. Değerler şifreli saklanır ve checkout sırasında atomik olarak rezerve edilir.</p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="add_keys"><label class="search-wide"><span>Anahtarlar</span>'
            .'<textarea name="keys" rows="8" maxlength="2000000" required></textarea></label>'
            .'<div class="search-actions"><button type="submit">Havuza ekle</button></div></form></section>'
            .'<p><a href="'.self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">İlan yönetimine dön</a></p></section>';
        return ProfileHtml::page('Dijital Teslimat',$body,$basePath,authenticated:true);
    }

    public static function revealed(
        MarketplaceOrder $order,
        MarketplaceDeliveryRecord $record,
        string $value,
        BasePath $basePath,
    ):string{
        $body='<section class="card"><h1>Teslimat Bilgisi</h1>'
            .'<div class="search-alert">Bu değer hassastır. Paylaşmadan önce hedefi doğrulayın.</div>'
            .'<dl class="market-specs"><dt>Sipariş</dt><dd>'.self::e($order->orderNumber).'</dd>'
            .'<dt>Teslimat türü</dt><dd>'.self::e($record->type->value).'</dd></dl>'
            .'<pre class="card" style="white-space:pre-wrap;overflow-wrap:anywhere">'.self::e($value).'</pre>'
            .'<p><a href="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value())).'">Siparişe dön</a></p></section>';
        return ProfileHtml::page('Teslimat Bilgisi',$body,$basePath,authenticated:true);
    }

    private static function option(string $value,string $label,string $selected):string
    {
        return '<option value="'.self::e($value).'"'.($value===$selected?' selected':'').'>'.self::e($label).'</option>';
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
