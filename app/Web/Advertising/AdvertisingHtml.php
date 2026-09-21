<?php

declare(strict_types=1);

namespace Forwext\App\Web\Advertising;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Advertising\AdvertisingCampaign;
use Forwext\Core\Advertising\AdvertisingDevice;
use Forwext\Core\Advertising\AdvertisingKind;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;

final class AdvertisingHtml
{
    /**
     * @param list<AdvertisingCampaign> $campaigns
     * @param list<array{campaign_id:EntityId,name:string,kind:AdvertisingKind,currency:string,impressions:int,clicks:int,revenue_minor:int}> $analytics
     * @param array<string,string> $placements
     * @param list<array{id:EntityId,name:string}> $groups
     * @param list<array{id:EntityId,title:string}> $forums
     * @param list<string> $routeTargets
     * @param list<EntityId> $forumTargets
     * @param list<EntityId> $groupTargets
     * @param list<AdvertisingDevice> $deviceTargets
     */
    public static function manage(
        array $campaigns,
        array $analytics,
        array $placements,
        array $groups,
        array $forums,
        ?AdvertisingCampaign $selected,
        array $routeTargets,
        array $forumTargets,
        array $groupTargets,
        array $deviceTargets,
        bool $canAds,
        bool $canNotices,
        BasePath $basePath,
        string $csrf,
        bool $updated,
    ):string{
        $action=self::e($basePath->prepend('/admin/advertising'));
        $body='<section class="card"><h1>Reklam, Notice ve Placement Yönetimi</h1>'
            .'<p class="muted">Route, forum, grup, cihaz ve zaman hedeflemesi; frequency cap ve performans ölçümü tek merkezde yönetilir.</p>'
            .($updated?'<div class="search-alert market-success">Kampanya kaydedildi.</div>':'')
            .'<section class="section"><h2>Kampanyalar</h2>';
        if($campaigns===[])$body.='<p class="muted">Henüz yönetebildiğiniz kampanya yok.</p>';
        foreach($campaigns as $campaign){
            $body.='<article class="search-hit"><div class="search-hit-type">'.self::e($campaign->kind->value).'</div>'
                .'<h3><a href="'.$action.'?id='.$campaign->campaignId->value().'">'.self::e($campaign->name).'</a></h3>'
                .'<p class="muted">'.self::e($campaign->placementKey).' · '.($campaign->enabled?'Aktif':'Pasif')
                .' · öncelik '.self::e((string)$campaign->priority).'</p></article>';
        }
        $body.='</section>';

        $selectedForums=[];foreach($forumTargets as $id)$selectedForums[$id->value()]=true;
        $selectedGroups=[];foreach($groupTargets as $id)$selectedGroups[$id->value()]=true;
        $selectedDevices=[];foreach($deviceTargets as $device)$selectedDevices[$device->value]=true;

        $kindOptions='';
        if($canAds)$kindOptions.=self::option('advertisement','Reklam',$selected?->kind->value??'advertisement');
        if($canNotices){
            $kindOptions.=self::option('notice','Notice',$selected?->kind->value??'advertisement');
            $kindOptions.=self::option('announcement','Duyuru',$selected?->kind->value??'advertisement');
        }

        $body.='<section class="section"><h2>'.($selected===null?'Yeni kampanya':'Kampanyayı düzenle').'</h2>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="save">'
            .'<input type="hidden" name="campaign_id" value="'.self::e($selected?->campaignId->value()??'').'">'
            .'<label><span>Tür</span><select name="kind" required>'.$kindOptions.'</select></label>'
            .'<label><span>Placement</span><select name="placement_key" required>';
        foreach($placements as $key=>$label){
            $body.='<option value="'.self::e($key).'"'.(($selected?->placementKey??'page.top')===$key?' selected':'').'>'
                .self::e($label).' ('.self::e($key).')</option>';
        }
        $body.='</select></label>'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($selected?->key??'').'"></label>'
            .'<label><span>Yönetim adı</span><input name="name" maxlength="120" required value="'.self::e($selected?->name??'').'"></label>'
            .'<label class="search-wide"><span>Başlık</span><input name="headline" maxlength="160" required value="'.self::e($selected?->headline??'').'"></label>'
            .'<label class="search-wide"><span>Metin</span><textarea name="body" maxlength="4000" rows="4">'.self::e($selected?->body??'').'</textarea></label>'
            .'<label class="search-wide"><span>Hedef URL (boş olabilir; site içi /path veya HTTPS)</span><input name="destination_url" maxlength="2048" value="'.self::e($selected?->destinationUrl??'').'"></label>'
            .'<label><span>Öncelik</span><input type="number" min="-32768" max="32767" name="priority" value="'.self::e((string)($selected?->priority??0)).'"></label>'
            .'<label><span>Para birimi</span><input name="currency" maxlength="3" value="'.self::e($selected?->currency??'TRY').'" required></label>'
            .'<label><span>Gösterim değeri</span><input name="impression_value" value="'.self::e(self::decimal($selected?->impressionValueMinor??0)).'"></label>'
            .'<label><span>Tıklama değeri</span><input name="click_value" value="'.self::e(self::decimal($selected?->clickValueMinor??0)).'"></label>'
            .'<label><span>Frequency cap</span><input type="number" min="1" max="100000" name="frequency_cap" value="'.self::e($selected?->frequencyCap===null?'':(string)$selected->frequencyCap).'"></label>'
            .'<label><span>Frequency penceresi (sn)</span><input type="number" min="60" max="2678400" name="frequency_window_seconds" value="'.self::e($selected?->frequencyWindowSeconds===null?'':(string)$selected->frequencyWindowSeconds).'"></label>'
            .'<label><span>Başlangıç UTC</span><input type="datetime-local" name="starts_at" value="'.self::e(self::datetimeLocal($selected?->startsAt)).'"></label>'
            .'<label><span>Bitiş UTC</span><input type="datetime-local" name="ends_at" value="'.self::e(self::datetimeLocal($selected?->endsAt)).'"></label>'
            .'<label><input type="checkbox" name="enabled" value="1"'.(($selected?->enabled??true)?' checked':'').'> Aktif</label>'
            .'<label class="search-wide"><span>Route hedefleri (satır başına desen; ör. forum.* veya *)</span>'
            .'<textarea name="route_targets" rows="4">'.self::e(implode("\n",$routeTargets)).'</textarea></label>'
            .'<label class="search-wide"><span>Forum hedefleri (boş = tüm forumlar)</span><select name="forum_ids[]" multiple size="7">';
        foreach($forums as $forum){
            $id=$forum['id']->value();
            $body.='<option value="'.self::e($id).'"'.(isset($selectedForums[$id])?' selected':'').'>'.self::e($forum['title']).'</option>';
        }
        $body.='</select></label><label class="search-wide"><span>Grup hedefleri (boş = tüm gruplar/ziyaretçiler)</span><select name="group_ids[]" multiple size="7">';
        foreach($groups as $group){
            $id=$group['id']->value();
            $body.='<option value="'.self::e($id).'"'.(isset($selectedGroups[$id])?' selected':'').'>'.self::e($group['name']).'</option>';
        }
        $body.='</select></label><div class="search-wide"><span class="muted">Cihaz hedefleri (boş = tüm cihazlar)</span><div class="market-actions">'
            .'<label><input type="checkbox" name="device_targets[]" value="desktop"'.(isset($selectedDevices['desktop'])?' checked':'').'> Desktop</label>'
            .'<label><input type="checkbox" name="device_targets[]" value="mobile"'.(isset($selectedDevices['mobile'])?' checked':'').'> Mobile</label>'
            .'</div></div><div class="search-actions"><button type="submit">Kampanyayı kaydet</button></div></form></section>';

        $body.='<section class="section"><h2>Son 30 gün performansı</h2>'
            .'<div class="card" style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Kampanya</th><th>Gösterim</th><th>Tıklama</th><th>CTR</th><th>Tahmini gelir</th></tr></thead><tbody>';
        if($analytics===[])$body.='<tr><td colspan="5" class="muted">Henüz performans verisi yok.</td></tr>';
        foreach($analytics as $row){
            $ctr=$row['impressions']>0?($row['clicks']/$row['impressions']*100):0.0;
            $body.='<tr><td>'.self::e($row['name']).'</td><td style="text-align:center">'.number_format($row['impressions']).'</td>'
                .'<td style="text-align:center">'.number_format($row['clicks']).'</td><td style="text-align:center">'.number_format($ctr,2).'%</td>'
                .'<td style="text-align:center">'.self::e(self::money($row['revenue_minor'],$row['currency'])).'</td></tr>';
        }
        $body.='</tbody></table></div></section></section>';

        return ProfileHtml::page('Reklam / Notice Yönetimi',$body,$basePath,authenticated:true);
    }

    private static function option(string $value,string $label,string $selected):string
    {
        return '<option value="'.self::e($value).'"'.($value===$selected?' selected':'').'>'.self::e($label).'</option>';
    }

    private static function csrf(string $token):string{return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';}
    private static function decimal(int $minor):string{return number_format($minor/100,2,'.','');}
    private static function money(int $minor,string $currency):string{return number_format($minor/100,2,',','.').' '.$currency;}
    private static function datetimeLocal(?\DateTimeImmutable $at):string{return $at?->format('Y-m-d\\TH:i')??'';}
    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
