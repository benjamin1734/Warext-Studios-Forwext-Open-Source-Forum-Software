<?php

declare(strict_types=1);

namespace Forwext\App\Web\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Subscription\SubscriptionPlan;
use Forwext\Core\Subscription\SubscriptionPurchase;
use Forwext\Core\Subscription\UserSubscription;
use Forwext\Core\Routing\BasePath;

final class SubscriptionHtml
{
    /**
     * @param list<SubscriptionPlan> $plans
     * @param list<UserSubscription> $subscriptions
     * @param list<SubscriptionPurchase> $purchases
     * @param list<string> $providers
     */
    public static function account(
        array $plans,array $subscriptions,array $purchases,array $providers,bool $canPurchase,
        BasePath $basePath,string $csrf,?string $paymentStatus=null
    ):string{
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $byPlan=[];foreach($subscriptions as $subscription)$byPlan[$subscription->planId->value()]=$subscription;
        $body='<section class="card"><h1>Abonelikler / User Upgrades</h1>'
            .'<p class="muted">Süreli veya süresiz forum yükseltmelerinizi ve ödeme durumlarını buradan yönetin.</p>'
            .($paymentStatus===null?'':'<div class="search-alert market-success">Ödeme durumu: '.self::e($paymentStatus).'</div>')
            .'</section><section class="section"><div class="market-grid">';
        if($plans===[])$body.='<div class="card empty">Şu anda aktif upgrade planı bulunmuyor.</div>';
        foreach($plans as $plan){
            $subscription=$byPlan[$plan->planId->value()]??null;
            $active=$subscription?->activeAt($now)??false;
            $duration=$plan->durationDays===null?'Süresiz':$plan->durationDays.' gün';
            $body.='<article class="card"><div class="search-hit-type">'.($active?'Aktif upgrade':'Upgrade planı').'</div>'
                .'<h2>'.self::e($plan->name).'</h2><p>'.nl2br(self::e($plan->description),false).'</p>'
                .'<p class="market-price">'.self::e(self::money($plan->priceMinor,$plan->currency)).'</p>'
                .'<p class="muted">Süre: '.self::e($duration).'</p>';
            if($subscription!==null){
                $body.='<p><strong>Durum:</strong> '.self::e($subscription->state->value)
                    .' · <strong>Bitiş:</strong> '.self::e($subscription->endsAt?->format('Y-m-d H:i').' UTC'??'Süresiz').'</p>';
            }
            if($canPurchase&&$plan->priceMinor>0&&$providers!==[]){
                $action=self::e($basePath->prepend('/account/upgrades/'.$plan->planId->value().'/purchase'));
                $body.='<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
                    .'<input type="hidden" name="idempotency_key" value="'.bin2hex(random_bytes(16)).'">'
                    .'<label><span>Ödeme sağlayıcısı</span><select name="provider_key" required>';
                foreach($providers as $provider)$body.='<option value="'.self::e($provider).'">'.self::e($provider).'</option>';
                $body.='</select></label><div class="search-actions"><button type="submit">'
                    .($active?'Süreyi uzat':'Satın al').'</button></div></form>';
            }elseif($plan->priceMinor===0){
                $body.='<p class="muted">Bu plan yalnızca yönetici atamasıyla verilir.</p>';
            }elseif($providers===[]){
                $body.='<p class="muted">Şu anda yapılandırılmış ödeme sağlayıcısı yok.</p>';
            }
            $body.='</article>';
        }
        $body.='</div></section><section class="card section"><h2>Ödeme geçmişim</h2>';
        if($purchases===[])$body.='<p class="muted">Henüz upgrade ödemesi yok.</p>';
        foreach($purchases as $purchase){
            $body.='<article class="search-hit"><strong>'.self::e($purchase->state->value).'</strong>'
                .'<p>'.self::e(self::money($purchase->amountMinor,$purchase->currency))
                .' · '.self::e($purchase->providerKey).' · '.self::e($purchase->createdAt->format('Y-m-d H:i')).' UTC</p></article>';
        }
        $body.='</section>';
        return ProfileHtml::page('Abonelikler / User Upgrades',$body,$basePath,authenticated:true);
    }

    /**
     * @param list<SubscriptionPlan> $plans
     * @param list<UserSubscription> $subscriptions
     * @param list<array{id:EntityId,name:string}> $roles
     * @param list<string> $permissions
     * @param list<EntityId> $selectedRoles
     * @param list<string> $selectedPermissions
     * @param array<string,string> $usernames
     */
    public static function manage(
        array $plans,array $subscriptions,array $roles,array $permissions,?SubscriptionPlan $selected,
        array $selectedRoles,array $selectedPermissions,array $usernames,BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/admin/subscriptions'));
        $body='<section class="card"><h1>Abonelik / User Upgrade Yönetimi</h1>'
            .'<p class="muted">Plan, rol/izin entitlement, manuel atama, renewal ve expiry yönetimi.</p>'
            .($updated?'<div class="search-alert market-success">İşlem kaydedildi.</div>':'')
            .'<section class="section"><h2>Planlar</h2><div class="market-list">';
        foreach($plans as $plan){
            $body.='<article class="search-hit"><h3><a href="'.$action.'?id='.$plan->planId->value().'">'.self::e($plan->name).'</a></h3>'
                .'<p class="muted">'.self::e($plan->key).' · '.self::e(self::money($plan->priceMinor,$plan->currency))
                .' · '.($plan->durationDays===null?'Süresiz':$plan->durationDays.' gün').' · '.($plan->active?'Aktif':'Pasif').'</p></article>';
        }
        if($plans===[])$body.='<p class="muted">Henüz plan yok.</p>';
        $body.='</div></section>';

        $roleSelected=[];foreach($selectedRoles as $id)$roleSelected[$id->value()]=true;
        $permissionSelected=array_fill_keys($selectedPermissions,true);
        $body.='<section class="section"><h2>'.($selected===null?'Yeni plan':'Planı düzenle').'</h2>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="save_plan"><input type="hidden" name="plan_id" value="'.self::e($selected?->planId->value()??'').'">'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($selected?->key??'').'"></label>'
            .'<label><span>Ad</span><input name="name" maxlength="120" required value="'.self::e($selected?->name??'').'"></label>'
            .'<label><span>Fiyat</span><input name="price" required value="'.self::e($selected===null?'0.00':self::decimal($selected->priceMinor)).'"></label>'
            .'<label><span>Para birimi</span><input name="currency" maxlength="3" required value="'.self::e($selected?->currency??'TRY').'"></label>'
            .'<label><span>Süre (gün, boş = süresiz)</span><input type="number" min="1" max="3650" name="duration_days" value="'.self::e($selected?->durationDays===null?'':(string)$selected->durationDays).'"></label>'
            .'<label><span>Sıra</span><input type="number" min="0" max="65535" name="sort_order" value="'.self::e((string)($selected?->sortOrder??100)).'"></label>'
            .'<label><input type="checkbox" name="active" value="1"'.(($selected?->active??true)?' checked':'').'> Aktif</label>'
            .'<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="20000" rows="5">'.self::e($selected?->description??'').'</textarea></label>'
            .'<label class="search-wide"><span>Verilecek roller</span><select name="role_ids[]" multiple size="6">';
        foreach($roles as $role){
            $body.='<option value="'.$role['id']->value().'"'.(isset($roleSelected[$role['id']->value()])?' selected':'').'>'.self::e($role['name']).'</option>';
        }
        $body.='</select></label><label class="search-wide"><span>Verilecek flag izinleri</span><select name="permission_keys[]" multiple size="10">';
        foreach($permissions as $permission){
            $body.='<option value="'.self::e($permission).'"'.(isset($permissionSelected[$permission])?' selected':'').'>'.self::e($permission).'</option>';
        }
        $body.='</select></label><div class="search-actions"><button type="submit">Planı kaydet</button></div></form></section>';

        $body.='<section class="section"><h2>Manuel upgrade ata</h2><form method="post" action="'.$action.'" class="search-form">'
            .self::csrf($csrf).'<input type="hidden" name="action" value="grant">'
            .'<label><span>Kullanıcı adı</span><input name="username" maxlength="64" required></label>'
            .'<label><span>Plan</span><select name="plan_id" required>';
        foreach($plans as $plan)$body.='<option value="'.$plan->planId->value().'">'.self::e($plan->name).'</option>';
        $body.='</select></label><div class="search-actions"><button type="submit">Ata / yenile</button></div></form></section>';

        $body.='<section class="section"><h2>Atamalar</h2>'
            .'<form method="post" action="'.$action.'" class="market-actions">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="expire_due"><button type="submit">Süresi dolanları işle</button></form>';
        foreach($subscriptions as $subscription){
            $username=$usernames[$subscription->userId->value()]??$subscription->userId->value();
            $planName=$subscription->planId->value();
            foreach($plans as $plan)if($plan->planId->equals($subscription->planId)){$planName=$plan->name;break;}
            $body.='<article class="search-hit"><strong>'.self::e($username).' · '.self::e($planName).'</strong>'
                .'<p class="muted">'.self::e($subscription->state->value).' · '
                .self::e($subscription->endsAt?->format('Y-m-d H:i').' UTC'??'Süresiz').'</p>';
            if($subscription->state->value!=='revoked'){
                $body.='<form method="post" action="'.$action.'" class="market-actions">'.self::csrf($csrf)
                    .'<input type="hidden" name="action" value="revoke"><input type="hidden" name="subscription_id" value="'.$subscription->subscriptionId->value().'">'
                    .'<button type="submit">İptal et</button></form>';
            }
            $body.='</article>';
        }
        $body.='</section></section>';
        return ProfileHtml::page('Abonelik Yönetimi',$body,$basePath,authenticated:true);
    }

    private static function csrf(string $token):string{return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';}
    private static function money(int $minor,string $currency):string{return number_format($minor/100,2,',','.').' '.$currency;}
    private static function decimal(int $minor):string{return number_format($minor/100,2,'.','');}
    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
