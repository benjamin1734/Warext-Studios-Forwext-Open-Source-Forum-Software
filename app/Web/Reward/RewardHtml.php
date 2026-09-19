<?php

declare(strict_types=1);

namespace Forwext\App\Web\Reward;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Reward\RewardBinding;
use Forwext\Core\Reward\RewardDefinition;
use Forwext\Core\Reward\RewardGrant;
use Forwext\Core\Reward\RewardSourceOption;
use Forwext\Core\Reward\RewardTargetOption;
use Forwext\Core\Routing\BasePath;

final class RewardHtml
{
    /**
     * @param array{definitions:list<RewardDefinition>,bindings:list<RewardBinding>,retryable:list<RewardGrant>,providers:list<string>,targets:array<string,list<RewardTargetOption>>,giveaways:list<RewardSourceOption>,trophies:list<RewardSourceOption>} $snapshot
     */
    public static function manage(
        array $snapshot,?RewardDefinition $selectedDefinition,?RewardBinding $selectedBinding,
        BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/admin/rewards'));
        $body='<section class="card"><h1>Ortak Reward Provider</h1>'
            .'<p class="muted">Referral, giveaway, trophy ve promotion kaynaklarını aynı idempotent fulfilment ledger’ında yönetin. '
            .'Otomatik provider yalnız güvenli custom role veya non-system secondary group hedeflerini kabul eder.</p>'
            .($updated?'<div class="search-alert" style="border-color:#2f6f47;background:#173722">Reward ayarları kaydedildi.</div>':'');

        $body.='<section class="section"><h2>Reward tanımları</h2>';
        foreach($snapshot['definitions'] as $definition){
            $body.='<article class="search-hit"><h3><a href="'.self::e($basePath->prepend(
                '/admin/rewards?reward_id='.rawurlencode($definition->rewardId->value())
            )).'">'.self::e($definition->name).'</a></h3><p class="muted"><code>'.self::e($definition->key)
                .'</code> · '.self::e($definition->providerKey).' · '.($definition->active?'etkin':'kapalı').'</p></article>';
        }
        if($snapshot['definitions']===[])$body.='<div class="empty">Henüz reward tanımı yok.</div>';

        $targetOptions='';
        foreach($snapshot['targets'] as $providerKey=>$targets){
            if($targets===[])continue;
            $targetOptions.='<optgroup label="'.self::e($providerKey).'">';
            foreach($targets as $target){
                $value=$providerKey.':'.$target->targetId->value();
                $selected=$selectedDefinition!==null&&$selectedDefinition->providerKey===$providerKey
                    &&$selectedDefinition->targetId->equals($target->targetId)?' selected':'';
                $targetOptions.='<option value="'.self::e($value).'"'.$selected.'>'.self::e($target->label).'</option>';
            }
            $targetOptions.='</optgroup>';
        }
        $body.='<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="save_definition"><input type="hidden" name="reward_id" value="'
            .self::e($selectedDefinition?->rewardId->value()??'').'">'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($selectedDefinition?->key??'').'"></label>'
            .'<label><span>Ad</span><input name="name" maxlength="120" required value="'.self::e($selectedDefinition?->name??'').'"></label>'
            .'<label class="search-wide"><span>Güvenli provider hedefi</span><select name="provider_target" required>'
            .'<option value="">Seçiniz</option>'.$targetOptions.'</select></label>'
            .'<label><input type="checkbox" name="active" value="1"'.(($selectedDefinition?->active??true)?' checked':'').'> Etkin</label>'
            .'<div class="search-actions"><button type="submit">Reward tanımını kaydet</button></div></form></section>';

        $body.='<section class="section"><h2>Giveaway / Trophy binding</h2>';
        foreach($snapshot['bindings'] as $binding){
            $body.='<article class="search-hit"><h3><a href="'.self::e($basePath->prepend(
                '/admin/rewards?binding_id='.rawurlencode($binding->bindingId->value())
            )).'">'.self::e($binding->sourceType).' → '.self::e($binding->rewardKey).'</a></h3><p class="muted"><code>'
                .self::e($binding->sourceDefinitionId->value()).'</code> · units '.$binding->units.' · '
                .($binding->active?'etkin':'kapalı').'</p></article>';
        }
        $sources='<option value="">Seçiniz</option><optgroup label="Giveaway">';
        foreach($snapshot['giveaways'] as $option)$sources.='<option value="giveaway:'.self::e($option->sourceDefinitionId->value()).'">'.self::e($option->label).'</option>';
        $sources.='</optgroup><optgroup label="Trophy">';
        foreach($snapshot['trophies'] as $option)$sources.='<option value="trophy:'.self::e($option->sourceDefinitionId->value()).'">'.self::e($option->label).'</option>';
        $sources.='</optgroup>';
        $rewardOptions='';
        foreach($snapshot['definitions'] as $definition){
            $rewardOptions.='<option value="'.self::e($definition->key).'"'
                .($selectedBinding?->rewardKey===$definition->key?' selected':'').'>'.self::e($definition->name).'</option>';
        }
        $body.='<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="save_binding"><input type="hidden" name="binding_id" value="'
            .self::e($selectedBinding?->bindingId->value()??'').'">'
            .'<label class="search-wide"><span>Kaynak tanım</span><select name="source" required>'.$sources.'</select></label>'
            .'<label><span>Reward</span><select name="reward_key" required><option value="">Seçiniz</option>'.$rewardOptions.'</select></label>'
            .'<label><span>Units</span><input type="number" name="units" min="1" max="1000000" value="'.($selectedBinding?->units??1).'"></label>'
            .'<label><input type="checkbox" name="active" value="1"'.(($selectedBinding?->active??true)?' checked':'').'> Binding etkin</label>'
            .'<div class="search-actions"><button type="submit">Binding kaydet</button></div></form></section>';

        $body.='<section class="section"><h2>Bekleyen / başarısız fulfillment</h2>';
        foreach($snapshot['retryable'] as $grant){
            $body.='<article class="search-hit"><h3>'.self::e($grant->rewardKey).' · '.self::e($grant->state->value).'</h3>'
                .'<p class="muted">'.self::e($grant->sourceType).' / '.self::e($grant->sourceId).' · '
                .self::e($grant->failureCode??'pending').'</p><form method="post" action="'.$action.'">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="retry"><input type="hidden" name="grant_id" value="'
                .self::e($grant->grantId->value()).'"><button type="submit">Tekrar dene</button></form></article>';
        }
        if($snapshot['retryable']===[])$body.='<p class="muted">Bekleyen fulfillment yok.</p>';
        $body.='<form method="post" action="'.$action.'" class="presence-settings">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="retry_batch"><label>Batch limit <input type="number" name="limit" min="1" max="500" value="100"></label>'
            .'<button type="submit">Bounded retry çalıştır</button></form></section></section>';
        return ProfileHtml::page('Reward Provider',$body,$basePath,authenticated:true);
    }
    private static function csrf(string $v):string{return '<input type="hidden" name="_csrf" value="'.self::e($v).'">';}
    private static function e(string $v):string{return ProfileHtml::escape($v);}
}
