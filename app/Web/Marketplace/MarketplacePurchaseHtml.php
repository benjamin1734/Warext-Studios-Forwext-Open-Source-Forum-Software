<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceCartEntry;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use Forwext\Core\Marketplace\MarketplaceOrderHistoryEntry;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryRecord;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryType;
use Forwext\Core\Routing\BasePath;

final class MarketplacePurchaseHtml
{
    public static function internalSale(
        MarketplaceListing $listing,bool $enabled,BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/marketplace/manage/internal/'.$listing->listingId->value()));
        $body='<section class="module-manage-page discovery-page"><header class="surface-head module-manage-head"><div>'
            .'<span class="forum-eyebrow">DAHİLİ SATIŞ</span><h1>Dahili Satın Alım</h1><p>'.self::e($listing->title).'</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">İlan yönetimi</a></header>'
            .($updated?'<div class="notification-settings-notice" role="status">Dahili satın alım ayarı kaydedildi.</div>':'')
            .'<section class="surface-panel marketplace-setting-panel">
            .'<p>Bu seçenek açık olduğunda uygun kullanıcılar ilanı Forwext sepetine ekleyebilir. '
            .'Ödeme sağlayıcısı ve gerçek teslimat işleyicileri sonraki Marketplace adımlarında bağlanır.</p>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<label><span>Durum</span><select name="enabled"><option value="0"'.($enabled?'':' selected').'>Kapalı</option>'
            .'<option value="1"'.($enabled?' selected':'').'>Aktif</option></select></label>'
            .'<div class="search-actions"><button type="submit">Kaydet</button></div></form>'
            .'</section></section>';
        return ProfileHtml::page('Dahili Satın Alım',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceCartEntry> $entries */
    public static function cart(array $entries,BasePath $basePath,string $csrf):string
    {
        $body='<section class="market-commerce-page discovery-page"><header class="surface-head market-commerce-head"><div>'
            .'<span class="forum-eyebrow">MARKETPLACE</span><h1>Sepet</h1>'
            .'<p>Dahili satın alıma uygun ilanlarını ve checkout durumunu kontrol et.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/marketplace')).'">Marketplace’e dön</a></header>';

        if($entries===[]){
            $body.='<section class="surface-panel"><div class="surface-empty"><strong>Sepetin boş.</strong>'
                .'<span>Marketplace’ten dahili satın alıma açık ilanları ekleyebilirsin.</span></div></section></section>';
            return ProfileHtml::page('Marketplace Sepeti',$body,$basePath,authenticated:true);
        }

        $ready=true;
        $body.='<section class="surface-panel market-commerce-panel"><header><h2>Sepetteki ilanlar</h2><span>'
            .count($entries).'</span></header><div class="market-cart-list">';

        foreach($entries as $entry){
            $listing=$entry->listing;
            $title=$listing?->title??('Silinmiş ilan '.$entry->listingId->value());
            $price=$listing===null?'—':self::money($listing->price->minorUnits,$listing->price->currency);
            if(!$entry->purchasable)$ready=false;

            $body.='<article class="market-cart-row'.($entry->purchasable?'':' is-unavailable').'"><div class="market-cart-copy">'
                .'<span>'.($entry->purchasable?'SATIN ALINABİLİR':'KULLANILAMIYOR').'</span>'
                .'<strong>'.self::e($title).'</strong><small>'.self::e($price)
                .($entry->unavailableReason===null?'':' · '.self::e($entry->unavailableReason)).'</small></div>'
                .'<form method="post" action="'.self::e($basePath->prepend('/marketplace/cart/'.$entry->listingId->value()))
                .'" class="market-cart-remove">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="remove"><button class="fx-btn" type="submit">Sepetten çıkar</button></form></article>';
        }

        $body.='</div></section>';

        if($ready){
            $body.='<div class="market-commerce-actions"><a class="fx-btn fx-btn--primary" href="'
                .self::e($basePath->prepend('/marketplace/checkout')).'">Checkout’a geç</a></div>';
        }else{
            $body.='<div class="auth-entry-error" role="alert">Checkout öncesinde kullanılamayan ilanları sepetten çıkar.</div>';
        }

        $body.='</section>';
        return ProfileHtml::page('Marketplace Sepeti',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceCartEntry> $entries */
    public static function checkout(array $entries,BasePath $basePath,string $csrf,string $checkoutKey):string
    {
        $totalByCurrency=[];
        $items='';

        foreach($entries as $entry){
            if(!$entry->purchasable||$entry->listing===null)continue;
            $listing=$entry->listing;
            $totalByCurrency[$listing->price->currency]=($totalByCurrency[$listing->price->currency]??0)+$listing->price->minorUnits;
            $items.='<article class="market-checkout-item"><strong>'.self::e($listing->title).'</strong><span>'
                .self::e(self::money($listing->price->minorUnits,$listing->price->currency)).'</span></article>';
        }

        $totals=[];
        foreach($totalByCurrency as $currency=>$minor){
            $totals[]=self::money($minor,$currency);
        }

        $action=self::e($basePath->prepend('/marketplace/checkout'));
        $body='<section class="market-commerce-page discovery-page"><header class="surface-head market-commerce-head"><div>'
            .'<span class="forum-eyebrow">MARKETPLACE</span><h1>Checkout</h1>'
            .'<p>Sipariş özeti ve faturalama bilgileri.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/marketplace/cart')).'">Sepete dön</a></header>'
            .'<section class="surface-panel market-checkout-summary"><header><h2>Sipariş özeti</h2><strong>'
            .self::e(implode(' + ',$totals)).'</strong></header><div class="market-checkout-items">'.$items.'</div></section>'
            .'<section class="surface-panel market-checkout-form-panel"><form method="post" action="'.$action.'" class="search-form market-checkout-form">'
            .self::csrf($csrf)
            .'<input type="hidden" name="checkout_key" value="'.self::e($checkoutKey).'">'
            .'<label><span>Ad / unvan</span><input name="billing_name" maxlength="160" required></label>'
            .'<label><span>E-posta</span><input type="email" name="billing_email" maxlength="254" required></label>'
            .'<label><span>Ülke kodu</span><input name="billing_country" maxlength="2" value="TR" required></label>'
            .'<label><span>Vergi / kimlik bilgisi (opsiyonel)</span><input name="billing_tax_id" maxlength="64"></label>'
            .'<label class="search-wide"><span>Fatura adresi (opsiyonel)</span><textarea name="billing_address" maxlength="1000" rows="4"></textarea></label>'
            .'<div class="market-commerce-actions search-wide"><button class="fx-btn fx-btn--primary" type="submit">Siparişi oluştur</button></div>'
            .'</form></section></section>';

        return ProfileHtml::page('Marketplace Checkout',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceOrder> $orders */
    public static function orders(array $orders,EntityId $actor,BasePath $basePath,bool $created):string
    {
        $body='<section class="market-commerce-page discovery-page"><header class="surface-head market-commerce-head"><div>'
            .'<span class="forum-eyebrow">MARKETPLACE</span><h1>Siparişler</h1>'
            .'<p>Alış, satış ve yetkili görünümündeki sipariş kayıtlarını takip et.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/marketplace')).'">Marketplace’e dön</a></header>'
            .($created?'<div class="notification-settings-notice" role="status">Sipariş kaydı oluşturuldu.</div>':'')
            .'<section class="surface-panel market-commerce-panel"><header><h2>Sipariş geçmişi</h2><span>'
            .count($orders).'</span></header><div class="market-order-list">';

        if($orders===[]){
            $body.='<div class="surface-empty"><strong>Henüz sipariş yok.</strong>'
                .'<span>Alış veya satış işlemleri burada listelenecek.</span></div>';
        }else{
            foreach($orders as $order){
                $side=$order->buyerUserId->equals($actor)?'Alış':($order->sellerUserId->equals($actor)?'Satış':'Yönetim');
                $body.='<a class="market-order-row" href="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value())).'">'
                    .'<div><span>'.$side.'</span><strong>'.self::e($order->orderNumber).'</strong><small>'
                    .self::e($order->state->value).' · ödeme '.self::e($order->paymentState->value)
                    .' · teslimat '.self::e($order->deliveryState->value).'</small></div><b>'
                    .self::e(self::money($order->totalMinor,$order->currency)).'</b></a>';
            }
        }

        $body.='</div></section></section>';
        return ProfileHtml::page('Marketplace Siparişleri',$body,$basePath,authenticated:true);
    }

    /**
     * @param list<MarketplaceOrderItem> $items
     * @param list<MarketplaceDeliveryRecord> $deliveries
     * @param list<MarketplaceOrderHistoryEntry> $history
     * @param list<string> $paymentProviders
     */
    public static function order(
        MarketplaceOrder $order,array $items,array $deliveries,array $history,EntityId $actor,string $buyer,string $seller,bool $canCancel,
        array $paymentProviders,?string $paymentIdempotencyKey,?string $paymentCancelKey,
        BasePath $basePath,string $csrf,bool $cancelled,?string $paymentStatus=null
    ):string{
        $reconciliation=($order->receiptMetadata['payment_reconciliation_required']??false)===true;
        $body='<section class="market-commerce-page discovery-page"><header class="surface-head market-commerce-head"><div>'
            .'<span class="forum-eyebrow">SİPARİŞ</span><h1>'.self::e($order->orderNumber).'</h1>'
            .'<p>Alıcı · '.self::e($buyer).' · Satıcı · '.self::e($seller).'</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/marketplace/orders')).'">Siparişlere dön</a></header>'
            .($cancelled?'<div class="notification-settings-notice" role="status">Sipariş iptal edildi.</div>':'')
            .($paymentStatus===null?'':'<div class="notification-settings-notice" role="status">Ödeme durumu · '.self::e($paymentStatus).'</div>')
            .($reconciliation?'<div class="auth-entry-error" role="alert">Ödeme sağlayıcısı ile sipariş durumu arasında uzlaştırma gerekiyor. Yetkili incelemesi veya refund gerekebilir.</div>':'')
            .'<section class="surface-panel market-order-summary"><div class="market-order-stats">'
            .self::summaryStat('Sipariş',$order->state->value)
            .self::summaryStat('Ödeme',$order->paymentState->value)
            .self::summaryStat('Teslimat',$order->deliveryState->value)
            .self::summaryStat('Toplam',self::money($order->totalMinor,$order->currency))
            .'</div><dl class="market-specs"><dt>Fatura adı</dt><dd>'.self::e($order->billing->name).'</dd>'
            .'<dt>Fatura e-postası</dt><dd>'.self::e($order->billing->email).'</dd>'
            .'<dt>Ülke</dt><dd>'.self::e($order->billing->countryCode).'</dd></dl></section>'
            .'<section class="surface-panel market-commerce-panel"><header><h2>Ürünler</h2><span>'.count($items)
            .'</span></header><div class="market-order-item-list">';

        $deliveryByItem=[];
        foreach($deliveries as $delivery){
            $deliveryByItem[$delivery->orderItemId->value()]=$delivery;
        }

        $isBuyer=$order->buyerUserId->equals($actor);
        $isSeller=$order->sellerUserId->equals($actor);

        foreach($items as $item){
            $delivery=$deliveryByItem[$item->itemId->value()]??null;
            $body.='<article class="market-order-item"><div class="market-order-item-copy"><strong>'
                .self::e($item->title).'</strong><small>'.self::e((string)$item->quantity).' × '
                .self::e(self::money($item->unitMinor,$item->currency));

            if($delivery!==null){
                $body.=' · teslimat '.self::e($delivery->type->value).' / '.self::e($delivery->state->value);
            }
            $body.='</small></div>';

            if($delivery!==null){
                $base='/marketplace/orders/'.$order->orderId->value().'/delivery/'.$item->itemId->value().'/';

                if($isBuyer&&in_array($delivery->state->value,['ready','delivered'],true)){
                    if($delivery->type===MarketplaceDeliveryType::Download){
                        $body.='<a class="fx-btn" href="'.self::e($basePath->prepend($base.'download')).'">Dosyayı indir</a>';
                    }else{
                        $body.='<form method="post" action="'.self::e($basePath->prepend($base.'reveal')).'" class="market-order-inline-form">'
                            .self::csrf($csrf).'<button class="fx-btn" type="submit">Teslimat bilgisini göster</button></form>';
                    }
                }

                if($isSeller&&$delivery->type===MarketplaceDeliveryType::Manual&&$delivery->state->value==='pending'
                    &&$order->paymentState->value==='paid'
                ){
                    $body.='<form method="post" action="'.self::e($basePath->prepend($base.'fulfill')).'" class="search-form market-order-fulfill">'
                        .self::csrf($csrf).'<label class="search-wide"><span>Manuel teslimat</span>'
                        .'<textarea name="value" maxlength="16384" rows="5" required></textarea></label>'
                        .'<div class="search-actions"><button type="submit">Teslimatı hazırla</button></div></form>';
                }
            }

            $body.='</article>';
        }
        $body.='</div></section>';

        if($paymentProviders!==[]&&$paymentIdempotencyKey!==null){
            $options='';
            foreach($paymentProviders as $provider){
                $options.='<option value="'.self::e($provider).'">'.self::e($provider).'</option>';
            }

            $body.='<section class="surface-panel market-order-action-panel"><h2>Ödeme</h2>'
                .'<form method="post" action="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value().'/payment')).'" class="search-form">'
                .self::csrf($csrf)
                .'<input type="hidden" name="idempotency_key" value="'.self::e($paymentIdempotencyKey).'">'
                .'<label><span>Ödeme sağlayıcısı</span><select name="provider_key" required>'.$options.'</select></label>'
                .'<div class="search-actions"><button class="fx-btn fx-btn--primary" type="submit">Ödemeyi başlat</button></div></form></section>';
        }

        if($canCancel&&$paymentCancelKey!==null){
            $body.='<form method="post" action="'.self::e($basePath->prepend('/marketplace/orders/'.$order->orderId->value())).'" class="market-commerce-actions">'
                .self::csrf($csrf).'<input type="hidden" name="action" value="cancel">'
                .'<input type="hidden" name="payment_cancel_key" value="'.self::e($paymentCancelKey).'">'
                .'<button class="fx-btn" type="submit">Ödenmemiş siparişi iptal et</button></form>';
        }

        $support=$basePath->prepend('/support/new').'?'.http_build_query([
            'context_type'=>'marketplace_order',
            'context_id'=>$order->orderId->value(),
            'q'=>'Sipariş '.$order->orderNumber,
        ],'','&',PHP_QUERY_RFC3986);

        $body.='<section class="surface-panel market-order-action-panel"><h2>Destek / uyuşmazlık</h2>'
            .'<p><a class="fx-btn" href="'.self::e($support).'">Bu sipariş için destek talebi aç</a></p></section>'
            .'<section class="surface-panel market-commerce-panel"><header><h2>Sipariş geçmişi</h2><span>'
            .count($history).'</span></header><div class="market-order-history">';

        if($history===[]){
            $body.='<div class="surface-empty"><strong>Geçmiş kaydı yok.</strong><span>Durum değişiklikleri burada görünecek.</span></div>';
        }else{
            foreach($history as $entry){
                $body.='<article class="market-order-history-row"><strong>'.self::e($entry->action).'</strong><small>'
                    .self::e($entry->createdAt->format('Y-m-d H:i:s')).' UTC · sipariş '
                    .self::e($entry->fromOrderState->value).' → '.self::e($entry->toOrderState->value).' · ödeme '
                    .self::e($entry->fromPaymentState->value).' → '.self::e($entry->toPaymentState->value).' · teslimat '
                    .self::e($entry->fromDeliveryState->value).' → '.self::e($entry->toDeliveryState->value).'</small></article>';
            }
        }

        $body.='</div></section></section>';
        return ProfileHtml::page($order->orderNumber,$body,$basePath,authenticated:true);
    }

    private static function summaryStat(string $label,string $value):string
    {
        return '<div><strong>'.self::e($value).'</strong><span>'.self::e($label).'</span></div>';
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
