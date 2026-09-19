<?php

declare(strict_types=1);

namespace Forwext\App\Web\Promotion;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Promotion\PromotionDefinition;
use Forwext\Core\Promotion\PromotionRuleType;
use Forwext\Core\Reward\RewardDefinition;
use Forwext\Core\Routing\BasePath;

final class PromotionHtml
{
    /** @param list<PromotionDefinition> $definitions @param list<RewardDefinition> $rewards */
    public static function manage(
        array $definitions,?PromotionDefinition $selected,array $rewards,BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/admin/promotions'));
        $body='<section class="card"><h1>User Promotions</h1>'
            .'<p class="muted">Kullanıcı metrikleri kurala ulaştığında ortak reward provider üzerinden güvenli role/group entitlement uygular.</p>'
            .($updated?'<div class="search-alert" style="border-color:#2f6f47;background:#173722">Promotion işlemi kaydedildi.</div>':'')
            .'<section class="section"><h2>Kurallar</h2>';
        foreach($definitions as $definition){
            $body.='<article class="search-hit"><h3><a href="'.self::e($basePath->prepend(
                '/admin/promotions?id='.rawurlencode($definition->promotionId->value())
            )).'">'.self::e($definition->name).'</a></h3><p class="muted">'
                .self::e($definition->ruleType->value).' ≥ '.$definition->threshold.' → '
                .self::e($definition->rewardKey).' · '.($definition->active?'etkin':'kapalı').'</p></article>';
        }
        if($definitions===[])$body.='<div class="empty">Henüz promotion kuralı yok.</div>';

        $rewardOptions='';
        foreach($rewards as $reward)$rewardOptions.='<option value="'.self::e($reward->key).'"'
            .($selected?->rewardKey===$reward->key?' selected':'').'>'.self::e($reward->name).'</option>';
        $rule=$selected?->ruleType??PromotionRuleType::AccountAgeDays;
        $body.='<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="save"><input type="hidden" name="promotion_id" value="'.self::e($selected?->promotionId->value()??'').'">'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($selected?->key??'').'"></label>'
            .'<label><span>Ad</span><input name="name" maxlength="120" required value="'.self::e($selected?->name??'').'"></label>'
            .'<label><span>Kural</span><select name="rule_type">'
            .self::option('account_age_days','Hesap yaşı (gün)',$rule->value)
            .self::option('visible_post_count','Görünür mesaj sayısı',$rule->value)
            .self::option('qualified_referral_count','Nitelikli referral',$rule->value)
            .self::option('giveaway_win_count','Güncel çekiliş kazanımı',$rule->value)
            .self::option('active_trophy_count','Aktif trophy/rozet sayısı',$rule->value).'</select></label>'
            .'<label><span>Eşik</span><input type="number" name="threshold" min="1" max="1000000000" value="'.($selected?->threshold??1).'"></label>'
            .'<label><span>Reward</span><select name="reward_key" required><option value="">Seçiniz</option>'.$rewardOptions.'</select></label>'
            .'<label><span>Units</span><input type="number" name="units" min="1" max="1000000" value="'.($selected?->units??1).'"></label>'
            .'<label><span>Öncelik</span><input type="number" name="priority" min="0" max="65535" value="'.($selected?->priority??100).'"></label>'
            .'<label><input type="checkbox" name="active" value="1"'.(($selected?->active??true)?' checked':'').'> Etkin</label>'
            .'<label class="search-wide"><input type="checkbox" name="revoke_when_unqualified" value="1"'
            .(($selected?->revokeWhenUnqualified??false)?' checked':'').'> Koşul artık sağlanmıyorsa promotion tarafından yönetilen reward atamasını güvenli biçimde geri al</label>'
            .'<div class="search-actions"><button type="submit">Promotion kaydet</button></div></form></section>';

        $body.='<section class="section"><h2>cPanel / manuel değerlendirme</h2>'
            .'<form method="post" action="'.$action.'" class="presence-settings">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="evaluate_user"><label>Kullanıcı adı <input name="username" required></label>'
            .'<button type="submit">Kullanıcıyı değerlendir</button></form>'
            .'<form method="post" action="'.$action.'" class="presence-settings">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="evaluate_batch"><label>Batch limit <input type="number" name="limit" min="1" max="500" value="100"></label>'
            .'<button type="submit">Bounded batch çalıştır</button></form></section></section>';
        return ProfileHtml::page('User Promotions',$body,$basePath,authenticated:true);
    }
    private static function csrf(string $v):string{return '<input type="hidden" name="_csrf" value="'.self::e($v).'">';}
    private static function option(string $v,string $l,string $s):string{return '<option value="'.self::e($v).'"'.($v===$s?' selected':'').'>'.self::e($l).'</option>';}
    private static function e(string $v):string{return ProfileHtml::escape($v);}
}
