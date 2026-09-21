<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceCartEntry;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use Forwext\Core\Routing\BasePath;

final class MarketplacePurchaseHtml
{
    public static function internalSale(
        MarketplaceListing $listing,bool $enabled,BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/marketplace/manage/internal/'.$listing->listingId->value()));
        $body='<section class="card"><h1>Dahili Satın Alım</h1><p class="muted">İlan: '.self::e($listing->title).'</p>'
            .($updated?'<div class="search-alert market-success">Dahili satın alım ayarı kaydedildi.</div>':'')
            .'<p>Bu seçenek açık olduğunda uygun kullanıcılar ilanı Forwext sepetine ekleyebilir. '
            .'Ödeme sağlayıcısı ve gerçek teslimat işleyicileri sonraki Marketplace adımlarında bağlanır.</p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<label><span>Durum</span><select name="enabled"><option value="0"'.($enabled?'':' selected').'>Kapalı</option>'
            .'<option value="1"'.($enabled?' selected':'').'>Aktif</option></select></label>'
            .'<div class="search-actions"><button type="submit">Kaydet</button></div></form>'
            .'<p><a href="'.self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">İlan yönetimine dön</a></p></section>';
        return ProfileHtml::page('Dahili Satın Alım',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceCartEntry> $entries */
    public static function cart(array $entries,BasePath $basePath,string $csrf):string
    {
        $body='<section class="card"><h1>Marketplace Sepeti</h1><p class="muted">Sepetinizdeki dahili satın alım ilanları.</p>';
        if($entries===[]){
            $body.='<div class="empty">Sepetiniz boş.</div><p><a href="'.self::e($basePath->prepend('/marketplace')).'">Marketplace\'e dön</a></p></section>';
            return ProfileHtml::page('Marketplace Sepeti',$body,$basePath,authenticated:true);
        }
        $ready=true;
        foreach($entries as $entry){
            $listing=$entry->listing;
            $title=$listing?->title??('Silinmiş ilan '.$entry->listingId->value());
            $price=$listing===null?'—':self::money($listing->price->minorUnits,$listing->price->currency);
            if(!$entry->purchasable)$ready=false;
            $body.='<article class="search-hit"><div class="search-hit-type">'.($entry->purchasable?'Satın alınabilir':'Kullanılamıyor').'</div>'
                .'<h3>'.self::e($title).'</h3><p class="market-price">'.self::e($price).'</p>'
                .($entry->unavailableReason===null?'':'<p class="muted">'.self::e($entry->unavailableReason).'</p>')
                .'<form method="post" action="'.self::e($basePath->prepend('/marketplace/cart/'.$entry->listingId->value())).'" class="market-actions">'
                .self::csrf($csrf).'<input type="hidden" name="action" value="remove"><button type="submit">Sepetten çıkar</button></form></article>';
        }
        if($ready){
            $body.='<p><a class="market-manage-link" href="'.self::e($basePath->prepend('/marketplace/checkout')).'">Checkout\'a geç</a></p>';
        }else{
            $body.='<div class="search-alert">Checkout öncesinde kullanılamayan ilanları sepetten çıkarın.</div>';
        }
        return ProfileHtml::page('Marketplace Sepeti',$body.'</section>',$basePath,authenticated:true);
    }

    /** @param list<MarketplaceCartEntry> $entries */
    public static function checkout(array $entries,BasePath $basePath,string $csrf,string $checkoutKey):string
    {
        $totalByCurrency=[];$items='';
        foreach($entries as $entry){
            if(!$entry->purchasable||$entry->listing===null)continue;
            $listing=$entry->listing;
            $totalByCurrency[$listing->price->currency]=($totalByCurrency[$listing->price->currency]??0)+$listing->price->minorUnits;
            $items.='<li>'.self::e($listing->title).' · '.self::e(self::money($listing->price->minorUnits,$listing->price->currency)).'</li>';
        }
        $totals=[];foreach($totalByCurrency as $currency=>$minor)$totals[]=self::money($minor,$currency);
        $action=self::e($basePath->prepend('/marketplace/checkout'));
        $body='<section class="card"><h1>Marketplace Checkout</h1><p class="muted">Sipariş özeti</p><ul>'.$items.'</ul>'
            .'<p><strong>Toplam: '.self::e(implode(' + ',$totals)).'</strong></p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="checkout_key" value="'.self::e($checkoutKey).'">'
            .'<label><span>Ad / unvan</span><input name="billing_name" maxlength="160" required></label>'
            .'<label><span>E-posta</span><input type="email" name="billing_email" maxlength="254" required></label>'
            .'<label><span>Ülke kodu</span><input name="billing_country" maxlength="2" value="TR" required></label>'
            .'<label><span>Vergi / kimlik bilgisi (opsiyonel)</span><input name="billing_tax_id" maxlength="64"></label>'
            .'<label class="search-wide"><span>Fatura adresi (opsiyonel)</span><textarea name="billing_address" maxlength="1000" rows="4"></textarea></label>'
            .'<div class="search-actions"><button type="submit">Siparişi oluştur</button>'
            .'<a href="'.self::e($basePath->prepend('/marketplace/cart')).'">Sepete dön</a></div></form>'
            .'<p class="muted">Bu aşama sipariş kaydını oluşturur; gerçek ödeme sağlayıcısı 14.05 adımında bağlanacaktır.</p></section>';
        return ProfileHtml::page('Marketplace Checkout',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceOrder> $orders */
    public static function orders(array $orders,EntityId $actor,BasePath $basePath,bool $created):string
    {
        $body='<section class="card"><h1>Marketplace Siparişleri</h1>'
            .($created?'<div class="search-alert market-success">Sipariş kaydı oluşturuldu.</div>':'');
        if($orders===[])$body.='<div class="empty">Henüz sipariş kaydı yok.</div>';
        foreach($orders as $order){
            $side=$order->buyerUserId->equals($actor)?'Alış':($order->sellerUserId->equals($actor)?'Satış':'Yönetim');
            $body.='<article class="search-hit"><div class="search-hit-type">'.self::e($side).'</div><h3><a href="'
                .self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value())).'">'.self::e($order->orderNumber).'</a></h3>'
                .'<p class="market-price">'.self::e(self::money($order->totalMinor,$order->currency)).'</p>'
                .'<p class="muted">Sipariş: '.self::e($order->state->value).' · Ödeme: '.self::e($order->paymentState->value)
                .' · Teslimat: '.self::e($order->deliveryState->value).'</p></article>';
        }
        return ProfileHtml::page('Marketplace Siparişleri',$body.'</section>',$basePath,authenticated:true);
    }

    /** @param list<MarketplaceOrderItem> $items @param list<string> $paymentProviders */
    public static function order(
        MarketplaceOrder $order,array $items,string $buyer,string $seller,bool $canCancel,
        array $paymentProviders,?string $paymentIdempotencyKey,?string $paymentCancelKey,
        BasePath $basePath,string $csrf,bool $cancelled,?string $paymentStatus=null
    ):string{
        $body='<section class="card"><h1>'.self::e($order->orderNumber).'</h1>'
            .($cancelled?'<div class="search-alert market-success">Sipariş iptal edildi.</div>':'')
            .($paymentStatus===null?'':'<div class="search-alert market-success">Ödeme durumu: '.self::e($paymentStatus).'</div>')
            .'<p class="muted">Alıcı: '.self::e($buyer).' · Satıcı: '.self::e($seller).'</p>'
            .'<dl class="market-specs"><dt>Sipariş durumu</dt><dd>'.self::e($order->state->value).'</dd>'
            .'<dt>Ödeme durumu</dt><dd>'.self::e($order->paymentState->value).'</dd>'
            .'<dt>Teslimat durumu</dt><dd>'.self::e($order->deliveryState->value).'</dd>'
            .'<dt>Toplam</dt><dd>'.self::e(self::money($order->totalMinor,$order->currency)).'</dd>'
            .'<dt>Fatura adı</dt><dd>'.self::e($order->billing->name).'</dd>'
            .'<dt>Fatura e-postası</dt><dd>'.self::e($order->billing->email).'</dd>'
            .'<dt>Ülke</dt><dd>'.self::e($order->billing->countryCode).'</dd></dl>'
            .'<section class="section"><h2>Ürünler</h2>';
        foreach($items as $item){
            $body.='<article class="search-hit"><h3>'.self::e($item->title).'</h3><p>'
                .self::e((string)$item->quantity).' × '.self::e(self::money($item->unitMinor,$item->currency)).'</p></article>';
        }
        $body.='</section>';
        if($paymentProviders!==[]&&$paymentIdempotencyKey!==null){
            $options='';
            foreach($paymentProviders as $provider){
                $options.='<option value="'.self::e($provider).'">'.self::e($provider).'</option>';
            }
            $body.='<section class="section"><h2>Ödeme</h2>'
                .'<form method="post" action="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value().'/payment')).'" class="search-form">'
                .self::csrf($csrf)
                .'<input type="hidden" name="idempotency_key" value="'.self::e($paymentIdempotencyKey).'">'
                .'<label><span>Ödeme sağlayıcısı</span><select name="provider_key" required>'.$options.'</select></label>'
                .'<div class="search-actions"><button type="submit">Ödemeyi başlat</button></div></form></section>';
        }
        if($canCancel&&$paymentCancelKey!==null){
            $body.='<form method="post" action="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value())).'" class="market-actions">'
                .self::csrf($csrf).'<input type="hidden" name="action" value="cancel">'
                .'<input type="hidden" name="payment_cancel_key" value="'.self::e($paymentCancelKey).'">'
                .'<button type="submit">Ödenmemiş siparişi iptal et</button></form>';
        }
        return ProfileHtml::page($order->orderNumber,$body.'</section>',$basePath,authenticated:true);
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function money(int $minor,string $currency):string
    {
        return number_format($minor/100,2,',','.').' '.$currency;
    }

    private static function e(string $value):string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
