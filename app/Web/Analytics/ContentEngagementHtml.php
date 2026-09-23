<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Analytics\Engagement\ContentEngagementSnapshot;
use Forwext\Core\Routing\BasePath;

final class ContentEngagementHtml
{
    public static function page(ContentEngagementSnapshot $snapshot,BasePath $basePath):string
    {
        $base=self::e($basePath->prepend('/admin/analytics/content'));
        $overview=self::e($basePath->prepend('/admin/analytics'));
        $operations=self::e($basePath->prepend('/admin/analytics/operations'));
        $commerce=self::e($basePath->prepend('/admin/analytics/commerce'));
        $reports=self::e($basePath->prepend('/admin/analytics/reports'));

        $body='<section class="card"><h1 style="margin-top:0">İçerik ve Engagement Analizleri</h1>'
            .'<p class="muted">Forum/category/thread performansı ve privacy-aware arama etkileşimleri. '
            .'Tüm zaman aralıkları UTC tabanlıdır.</p>'
            .'<div class="market-actions"><a href="'.$overview.'">Forum genel dashboardu</a>';
        $body.='<a href="'.$operations.'">Moderasyon & operasyon</a>';
        $body.='<a href="'.$commerce.'">Marketplace & gelir</a>';
        $body.='<a href="'.$reports.'">Rapor builder</a>';
        foreach([7,30,90] as $days){
            $style=$snapshot->windowDays===$days?' style="font-weight:700"':'';
            $body.='<a'.$style.' href="'.$base.'?days='.$days.'">'.$days.' gün</a>';
        }
        $body.='</div></section>';

        $body.='<div class="stats-grid" style="margin-top:16px">'
            .self::stat('Reaction',self::n($snapshot->reactionCount),'Seçili dönemde')
            .self::stat('Bookmark',self::n($snapshot->bookmarkCount),'Seçili dönemde')
            .self::stat('Thread watch',self::n($snapshot->watchedThreadCount),'Yeni/güncellenen watch')
            .self::stat('Forum watch',self::n($snapshot->watchedForumCount),'Yeni/güncellenen watch')
            .self::stat('Follow',self::n($snapshot->followCount),'Seçili dönemde')
            .self::stat('Arama',self::n($snapshot->searchCount),'Tüm privacy-aware search eventleri')
            .self::stat('Redacted arama',self::n($snapshot->redactedSearchCount),'Terimi metin olarak tutulmayan')
            .self::stat('0 sonuç arama',self::n($snapshot->zeroResultSearchCount),'Sonuç bucket = zero')
            .'</div>';

        $body.=self::forumTable($snapshot);
        $body.=self::categoryTable($snapshot);
        $body.=self::threadTable($snapshot);
        $body.=self::followTable($snapshot);
        $body.=self::searchTable($snapshot);

        $body.='<section class="card" style="margin-top:16px"><h2>Oranların anlamı</h2>'
            .'<p class="muted">Thread tablosundaki watch/bookmark/reaction oranları gerçek satış conversion değildir. '
            .'Seçili dönem içindeki aksiyon sayısının aynı dönemde kaydedilen thread view sayısına oranıdır ve '
            .'engagement proxy olarak yorumlanmalıdır. Analytics event geçmişi sistemin kurulmasından önceki görüntülemeleri içermez.</p>'
            .'<p class="muted">Arama terimi yalnız sıkı güvenli-terim politikasından geçerse trend tablosunda gösterilir. '
            .'E-posta, URL, IP, telefon benzeri veya serbest/hassas sorgular yalnız redacted sınıfında sayılır.</p>'
            .'<p class="muted">Üretildi: '.self::e($snapshot->generatedAt->format('Y-m-d H:i:s')).' UTC</p></section>';

        return ProfileHtml::page('İçerik ve Engagement Analizleri',$body,$basePath,authenticated:true);
    }

    private static function forumTable(ContentEngagementSnapshot $snapshot):string
    {
        $body='<section class="card" style="margin-top:16px;overflow:auto"><h2>Forum performansı</h2>'
            .'<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Forum</th><th>Konu</th><th>Mesaj</th><th>View</th><th>Reaction</th><th>Watch</th>'
            .'</tr></thead><tbody>';
        if($snapshot->forums===[])$body.='<tr><td colspan="6" class="muted">Veri yok.</td></tr>';
        foreach($snapshot->forums as $row){
            $body.='<tr><td>'.self::e($row['title']).'</td><td>'.self::n($row['threads']).'</td>'
                .'<td>'.self::n($row['posts']).'</td><td>'.self::n($row['views']).'</td>'
                .'<td>'.self::n($row['reactions']).'</td><td>'.self::n($row['watches']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function categoryTable(ContentEngagementSnapshot $snapshot):string
    {
        $body='<section class="card" style="margin-top:16px;overflow:auto"><h2>Kategori performansı</h2>'
            .'<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Kategori</th><th>Forum</th><th>Konu</th><th>Mesaj</th><th>View</th><th>Reaction</th><th>Watch</th>'
            .'</tr></thead><tbody>';
        if($snapshot->categories===[])$body.='<tr><td colspan="7" class="muted">Veri yok.</td></tr>';
        foreach($snapshot->categories as $row){
            $body.='<tr><td>'.self::e($row['title']).'</td><td>'.self::n($row['forums']).'</td>'
                .'<td>'.self::n($row['threads']).'</td><td>'.self::n($row['posts']).'</td>'
                .'<td>'.self::n($row['views']).'</td><td>'.self::n($row['reactions']).'</td>'
                .'<td>'.self::n($row['watches']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function threadTable(ContentEngagementSnapshot $snapshot):string
    {
        $body='<section class="card" style="margin-top:16px;overflow:auto"><h2>Thread performansı</h2>'
            .'<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Konu</th><th style="text-align:left">Forum</th><th>Reply</th><th>View</th>'
            .'<th>Reaction</th><th>Bookmark</th><th>Watch</th><th>Reaction/View</th><th>Bookmark/View</th><th>Watch/View</th>'
            .'</tr></thead><tbody>';
        if($snapshot->threads===[])$body.='<tr><td colspan="10" class="muted">Veri yok.</td></tr>';
        foreach($snapshot->threads as $row){
            $body.='<tr><td>'.self::e($row['title']).'</td><td>'.self::e($row['forum_title']).'</td>'
                .'<td>'.self::n($row['replies']).'</td><td>'.self::n($row['views']).'</td>'
                .'<td>'.self::n($row['reactions']).'</td><td>'.self::n($row['bookmarks']).'</td>'
                .'<td>'.self::n($row['watches']).'</td><td>'.self::rate($row['reaction_rate']).'</td>'
                .'<td>'.self::rate($row['bookmark_rate']).'</td><td>'.self::rate($row['watch_rate']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function followTable(ContentEngagementSnapshot $snapshot):string
    {
        $body='<section class="card" style="margin-top:16px;overflow:auto"><h2>Follow performansı</h2>'
            .'<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Kullanıcı</th><th>Yeni follow</th>'
            .'</tr></thead><tbody>';
        if($snapshot->followLeaders===[])$body.='<tr><td colspan="2" class="muted">Follow verisi yok.</td></tr>';
        foreach($snapshot->followLeaders as $row){
            $body.='<tr><td>'.self::e($row['username']).'</td><td>'.self::n($row['follows']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function searchTable(ContentEngagementSnapshot $snapshot):string
    {
        $body='<section class="card" style="margin-top:16px;overflow:auto"><h2>Güvenli arama terimleri</h2>'
            .'<table style="width:100%;border-collapse:collapse"><thead><tr>'
            .'<th style="text-align:left">Terim</th><th>Arama</th><th>0 sonuç</th><th>Ort. dönen sonuç</th>'
            .'</tr></thead><tbody>';
        if($snapshot->searchTerms===[])$body.='<tr><td colspan="4" class="muted">Güvenli trend terimi yok.</td></tr>';
        foreach($snapshot->searchTerms as $row){
            $body.='<tr><td>'.self::e($row['term']).'</td><td>'.self::n($row['searches']).'</td>'
                .'<td>'.self::n($row['zero_results']).'</td><td>'.self::e(number_format($row['avg_results'],1,',','.')).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function stat(string $label,string $value,string $hint):string
    {
        return '<div class="card stat"><span class="muted">'.self::e($label).'</span>'
            .'<strong>'.self::e($value).'</strong><small class="muted">'.self::e($hint).'</small></div>';
    }

    private static function rate(?float $rate):string
    {
        return $rate===null?'—':self::e(number_format($rate,1,',','.').'%');
    }

    private static function n(int $value):string
    {
        return number_format($value,0,',','.');
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
