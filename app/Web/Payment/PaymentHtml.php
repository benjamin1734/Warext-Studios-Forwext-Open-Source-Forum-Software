<?php

declare(strict_types=1);

namespace Forwext\App\Web\Payment;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Payment\PaymentAttempt;
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Payment\PaymentRefund;
use Forwext\Core\Routing\BasePath;

final class PaymentHtml
{
    /**
     * @param array{attempts:list<PaymentAttempt>,refunds:array<string,list<PaymentRefund>>,providers:list<string>,can_refund:bool} $snapshot
     */
    public static function manage(array $snapshot,BasePath $basePath,string $csrf,bool $updated):string
    {
        $action=self::e($basePath->prepend('/admin/payments'));
        $body='<section class="card"><h1>Payment Operations</h1>'
            .'<p class="muted">Provider bağımsız ödeme denemeleri, doğrulanmış webhook durumları ve tam refund/cancel işlemleri.</p>'
            .($updated?'<div class="search-alert market-success">Payment işlemi kaydedildi.</div>':'')
            .'<section class="section"><h2>Kayıtlı provider’lar</h2>';

        if($snapshot['providers']===[])$body.='<div class="empty">Aktif ödeme provider’ı kayıtlı değil.</div>';
        foreach($snapshot['providers'] as $provider)$body.='<span class="market-badge">'.self::e($provider).'</span>';

        $body.='</section><section class="section"><h2>Son ödeme denemeleri</h2>';
        if($snapshot['attempts']===[])$body.='<div class="empty">Henüz ödeme denemesi yok.</div>';

        foreach($snapshot['attempts'] as $attempt){
            $body.='<article class="search-hit"><div class="search-hit-type">'.self::e($attempt->state->value).'</div>'
                .'<h3>'.self::e($attempt->providerKey).' · <code>'.self::e($attempt->attemptId->value()).'</code></h3>'
                .'<p class="muted">Order: <code>'.self::e($attempt->orderId->value()).'</code> · '
                .self::e(self::money($attempt->amountMinor,$attempt->currency)).' · '
                .self::e($attempt->updatedAt->format('Y-m-d H:i:s')).' UTC</p>'
                .($attempt->providerReference===null?'':'<p class="muted">Provider ref: <code>'.self::e($attempt->providerReference).'</code></p>');

            $refundBlocksNew=false;
            foreach($snapshot['refunds'][$attempt->attemptId->value()]??[] as $refund){
                if(in_array($refund->state,[\Forwext\Core\Payment\PaymentRefundState::Pending,\Forwext\Core\Payment\PaymentRefundState::Succeeded],true)){
                    $refundBlocksNew=true;
                }
                $body.='<p class="muted">Refund '.self::e($refund->state->value).' · '
                    .self::e(self::money($refund->amountMinor,$attempt->currency))
                    .($refund->providerRefundReference===null?'':' · <code>'.self::e($refund->providerRefundReference).'</code>').'</p>';
            }

            if($snapshot['can_refund']){
                if($attempt->state===PaymentAttemptState::Paid&&!$refundBlocksNew){
                    $body.=self::operation($action,$csrf,$attempt,'refund','Tam refund başlat');
                }elseif(in_array(
                    $attempt->state,
                    [PaymentAttemptState::Pending,PaymentAttemptState::RequiresAction,PaymentAttemptState::Authorized],
                    true
                )){
                    $body.=self::operation($action,$csrf,$attempt,'cancel','Ödeme denemesini iptal et');
                }
            }
            $body.='</article>';
        }

        $body.='</section></section>';
        return ProfileHtml::page('Payment Operations',$body,$basePath,authenticated:true);
    }

    private static function operation(
        string $action,string $csrf,PaymentAttempt $attempt,string $operation,string $label
    ):string{
        return '<form method="post" action="'.$action.'" class="market-actions">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="'.self::e($operation).'">'
            .'<input type="hidden" name="attempt_id" value="'.self::e($attempt->attemptId->value()).'">'
            .'<input type="hidden" name="idempotency_key" value="'.bin2hex(random_bytes(16)).'">'
            .'<button type="submit">'.self::e($label).'</button></form>';
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function money(int $minor,string $currency):string
    {
        return number_format($minor/100,2,',','.').' '.$currency;
    }

    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
