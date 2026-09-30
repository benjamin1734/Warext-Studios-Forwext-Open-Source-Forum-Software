<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Reporting\SupportStaffDashboard;

final class SupportStaffDashboardHtml
{
    public static function page(SupportStaffDashboard $dashboard,BasePath $basePath):string
    {
        $s=$dashboard->summary;
        $stats='<div class="staff-dashboard-stats">'
            .self::stat('Toplam',(string)$s->total)
            .self::stat('Aktif',(string)$s->active)
            .self::stat('Çözüldü',(string)$s->resolved)
            .self::stat('Kapalı',(string)$s->closed)
            .self::stat('İlk yanıt ihlali',(string)$s->firstResponseBreaches)
            .self::stat('Çözüm ihlali',(string)$s->resolutionBreaches)
            .self::stat('Ort. ilk yanıt',self::duration($s->averageFirstResponseSeconds))
            .self::stat('Ort. çözüm',self::duration($s->averageResolutionSeconds))
            .'</div>';

        $queue='';
        if($dashboard->queue===[]){
            $queue='<div class="surface-empty"><strong>Aktif destek talebi yok.</strong><span>Kuyruk şu anda temiz.</span></div>';
        }else{
            $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            foreach($dashboard->queue as $ticket){
                $href=$basePath->prepend('/support/tickets/'.rawurlencode($ticket->ticketId->value()));
                $firstBreach=$ticket->sla->firstResponseBreached($now);
                $resolutionBreach=$ticket->sla->resolutionBreached($now);
                $queue.='<a class="staff-queue-row" href="'.self::e($href).'"><div class="staff-queue-main">'
                    .'<span class="staff-queue-meta">'.self::e($ticket->priority->label()).' · '.self::e($ticket->status->label())
                    .($firstBreach?' · İlk yanıt SLA ihlali':'')
                    .($resolutionBreach?' · Çözüm SLA ihlali':'').'</span>'
                    .'<strong>'.self::e($ticket->subject).'</strong>'
                    .'<small>'.self::e($ticket->categoryKey).' · '.self::e($ticket->createdAt->format('Y-m-d H:i')).'</small>'
                    .'</div><span aria-hidden="true">→</span></a>';
            }
        }

        $categoryRows='';
        foreach($dashboard->categories as $metric){
            $categoryRows.='<tr><td>'.self::e($metric->categoryLabel).'</td><td>'.$metric->total.'</td><td>'.$metric->active.'</td>'
                .'<td>'.$metric->resolvedOrClosed.'</td><td>'.$metric->firstResponseBreaches.'</td><td>'.$metric->resolutionBreaches.'</td>'
                .'<td>'.self::e(self::duration($metric->averageFirstResponseSeconds)).'</td>'
                .'<td>'.self::e(self::duration($metric->averageResolutionSeconds)).'</td></tr>';
        }
        if($categoryRows===''){
            $categoryRows='<tr><td colspan="8" class="muted">Kategori metriği bulunmuyor.</td></tr>';
        }

        $audit='';
        if($dashboard->audit!==[]){
            foreach($dashboard->audit as $entry){
                $audit.='<li><time>'.self::e($entry->occurredAt->format('Y-m-d H:i:s')).'</time><span>'
                    .self::e($entry->action).' · '.self::e($entry->targetType.':'.$entry->targetId)
                    .' · request '.self::e($entry->requestId).'</span></li>';
            }
        }

        $body='<section class="staff-dashboard discovery-page"><header class="surface-head staff-dashboard-head"><div>'
            .'<span class="forum-eyebrow">DESTEK EKİBİ</span><h1>Destek paneli</h1>'
            .'<p>Aktif kuyruk, SLA sağlığı, kategori performansı ve audit hareketlerini tek yerde izle.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/support/tickets')).'">Taleplerim</a></header>'
            .$stats
            .'<section class="surface-panel staff-panel"><header><h2>Aktif kuyruk</h2><span>'.count($dashboard->queue).'</span></header>'
            .'<div class="staff-queue-list">'.$queue.'</div></section>'
            .'<section class="surface-panel staff-panel"><header><h2>Kategori istatistikleri</h2><span>'
            .count($dashboard->categories).'</span></header><div class="staff-table-wrap"><table><thead><tr>'
            .'<th>Kategori</th><th>Toplam</th><th>Aktif</th><th>Çözülen/Kapalı</th><th>İlk yanıt ihlali</th>'
            .'<th>Çözüm ihlali</th><th>Ort. ilk yanıt</th><th>Ort. çözüm</th></tr></thead><tbody>'
            .$categoryRows.'</tbody></table></div></section>';

        if($audit!==''){
            $body.='<section class="surface-panel staff-panel"><header><h2>Destek audit</h2><span>'
                .count($dashboard->audit).'</span></header><ul class="staff-audit-list">'.$audit.'</ul></section>';
        }

        $body.='</section>';
        return ProfileHtml::page('Destek paneli',$body,$basePath,authenticated:true);
    }

    private static function stat(string $label,string $value):string
    {
        return '<div class="staff-dashboard-stat"><strong>'.self::e($value).'</strong><span>'.self::e($label).'</span></div>';
    }

    private static function duration(?float $seconds):string
    {
        if($seconds===null) return '—';
        $seconds=max(0,(int)round($seconds));
        if($seconds>=86400) return number_format($seconds/86400,1).' gün';
        if($seconds>=3600) return number_format($seconds/3600,1).' saat';
        if($seconds>=60) return number_format($seconds/60,1).' dk';
        return $seconds.' sn';
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
