<?php

declare(strict_types=1);

namespace Forwext\App\Web\Trophy;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Trophy\TrophyDefinition;
use Forwext\Core\Trophy\TrophyKind;
use Forwext\Core\Trophy\TrophyRuleType;

final class TrophyHtml
{
    /** @param list<TrophyDefinition> $definitions */
    public static function manage(
        array $definitions,?TrophyDefinition $selected,bool $canManage,bool $canAward,
        BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/admin/trophies'));
        $body='<section class="card"><h1>Kupa, Rozet ve Başarım Yönetimi</h1>'
            .'<p class="muted">Kural bazlı ve manuel başarımları yönetin. Reward/group promotion işlemleri 13.08 ortak provider katmanına aittir.</p>'
            .($updated?'<div class="search-alert" style="border-color:#2f6f47;background:#173722">İşlem kaydedildi.</div>':'')
            .'<section class="section"><h2>Tanımlar</h2>';
        if($definitions===[])$body.='<div class="empty">Henüz tanım yok.</div>';
        foreach($definitions as $d){
            $name=$canManage
                ? '<a href="'.self::e($basePath->prepend('/admin/trophies?id='.rawurlencode($d->trophyId->value()))).'">'.self::e($d->name).'</a>'
                : self::e($d->name);
            $body.='<article class="search-hit"><div class="search-hit-type">'.self::e($d->kind->value)
                .' · '.($d->active?'Etkin':'Kapalı').' · Öncelik '.$d->priority.'</div><h3>'
                .$name.'</h3><p class="muted">'.self::e($d->ruleType->value)
                .($d->threshold===null?'':' ≥ '.$d->threshold).'</p></article>';
        }
        $body.='</section>';

        if($canManage){
            $rule=$selected?->ruleType??TrophyRuleType::Manual;
            $kind=$selected?->kind??TrophyKind::Badge;
            $body.='<section class="section"><h2>'.($selected===null?'Yeni tanım':'Tanımı düzenle').'</h2>'
                .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="save"><input type="hidden" name="trophy_id" value="'
                .self::e($selected?->trophyId->value()??'').'">'
                .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($selected?->key??'').'"></label>'
                .'<label><span>Ad</span><input name="name" maxlength="120" required value="'.self::e($selected?->name??'').'"></label>'
                .'<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="2000" rows="4">'.self::e($selected?->description??'').'</textarea></label>'
                .'<label><span>Tür</span><select name="kind">'.self::option('trophy','Kupa',$kind->value).self::option('badge','Rozet',$kind->value).self::option('achievement','Başarım',$kind->value).'</select></label>'
                .'<label><span>Öncelik</span><input type="number" name="priority" min="0" max="65535" value="'.($selected?->priority??100).'"></label>'
                .'<label><input type="checkbox" name="active" value="1"'.(($selected?->active??true)?' checked':'').'> Etkin</label>'
                .'<label><span>Icon path</span><input name="icon_path" maxlength="512" value="'.self::e($selected?->iconPath??'').'" placeholder="/assets/trophies/icon.webp"></label>'
                .'<label class="search-wide"><span>Banner path</span><input name="banner_path" maxlength="512" value="'.self::e($selected?->bannerPath??'').'" placeholder="/assets/trophies/banner.webp"></label>'
                .'<label><span>Kural</span><select name="rule_type">'
                .self::option('manual','Manuel',$rule->value).self::option('account_age_days','Hesap yaşı (gün)',$rule->value)
                .self::option('visible_post_count','Görünür mesaj sayısı',$rule->value).self::option('qualified_referral_count','Nitelikli referral',$rule->value)
                .self::option('giveaway_win_count','Güncel çekiliş kazanımı',$rule->value).'</select></label>'
                .'<label><span>Eşik (manuelde boş)</span><input type="number" name="threshold" min="1" max="1000000000" value="'.self::e($selected?->threshold===null?'':(string)$selected->threshold).'"></label>'
                .'<div class="search-actions"><button type="submit">Tanımı kaydet</button></div></form></section>';
        }

        if($canAward){
            $options='<option value="">Seçiniz</option>';
            foreach($definitions as $d)$options.='<option value="'.self::e($d->trophyId->value()).'">'.self::e($d->name).'</option>';
            $body.='<section class="section"><h2>Kullanıcı işlemleri</h2>'
                .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="evaluate"><label><span>Kullanıcı adı</span><input name="username" required></label>'
                .'<div class="search-actions"><button type="submit">Kural başarımlarını şimdi değerlendir</button></div></form>'
                .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
                .'<label><span>İşlem</span><select name="action"><option value="award">Manuel ver</option><option value="revoke">Geri al</option></select></label>'
                .'<label><span>Kullanıcı adı</span><input name="username" required></label>'
                .'<label><span>Kupa/Rozet</span><select name="trophy_id" required>'.$options.'</select></label>'
                .'<label class="search-wide"><span>Gerekçe (geri almada zorunlu)</span><textarea name="reason" maxlength="500" rows="3"></textarea></label>'
                .'<div class="search-actions"><button type="submit">İşlemi uygula</button></div></form></section>';
        }
        $body.='</section>';
        return ProfileHtml::page('Kupa/Rozet/Başarım Yönetimi',$body,$basePath,authenticated:true);
    }
    private static function csrf(string $v):string{return '<input type="hidden" name="_csrf" value="'.self::e($v).'">';}
    private static function option(string $v,string $label,string $selected):string{return '<option value="'.self::e($v).'"'.($v===$selected?' selected':'').'>'.self::e($label).'</option>';}
    private static function e(string $v):string{return ProfileHtml::escape($v);}
}
